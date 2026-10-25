<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Enums\MovementType;
use App\Enums\ReturnCondition;
use App\Enums\ReturnOutcome;
use App\Enums\SalesOrderStatus;
use App\Enums\StockUnitStatus;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sales returns: goods come back to the chosen location in the chosen condition, and whatever the
 * customer had paid beyond what they now owe is refunded, put on a credit note, or (exchange) put
 * on a credit note that pays for the linked new sale.
 *
 * Within company_settings.return_days of the sale no manager is needed; later, a manager approves.
 */
class ReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PaymentService $payments,
        private readonly CreditNoteService $creditNotes,
    ) {}

    /**
     * @param  array<int, array{sales_order_line_id: int, quantity: int, condition: ReturnCondition|string, restock_location_id?: int|null}>  $lines
     */
    public function process(
        SalesOrder $order,
        array $lines,
        ReturnOutcome $outcome,
        string $reason,
        User $actor,
        ?int $refundMethodId = null,
        ?string $refundReference = null,
        ?int $approverId = null,
        ?string $pin = null,
    ): SalesReturn {
        if ($lines === []) {
            throw new InvalidArgumentException('Choose at least one item to return.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Enter the reason for the return.');
        }

        // Checked before the transaction so a refused PIN stays in the audit log.
        $approver = $this->approveIfLate($order, $actor, $approverId, $pin);

        return DB::transaction(function () use ($order, $lines, $outcome, $reason, $actor, $refundMethodId, $refundReference, $approver) {
            $order = SalesOrder::query()->with(['lines.variant.product', 'customer'])->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Cancelled], true)) {
                throw new InvalidArgumentException("Sales order [{$order->number}] has nothing to return.");
            }

            $orderLines = $order->lines->keyBy('id');
            $previousAmounts = SalesReturnLine::query()
                ->whereIn('sales_order_line_id', $orderLines->keys())
                ->selectRaw('sales_order_line_id, SUM(amount) as total')
                ->groupBy('sales_order_line_id')
                ->pluck('total', 'sales_order_line_id');

            $prepared = [];
            foreach ($lines as $index => $raw) {
                /** @var SalesOrderLine|null $line */
                $line = $orderLines->get((int) $raw['sales_order_line_id']);
                $label = 'Line #'.($index + 1);

                if (! $line) {
                    throw new InvalidArgumentException("{$label} is not on order {$order->number}.");
                }

                $qty = (int) $raw['quantity'];
                $returnable = ($line->is_service ? $line->quantity : $line->fulfilled_qty) - $line->returned_qty;

                if ($qty <= 0) {
                    throw new InvalidArgumentException("{$label}: enter the quantity to return.");
                }

                if ($qty > $returnable) {
                    throw new InvalidArgumentException("{$line->name_snapshot}: only ".Quantity::format(max(0, $returnable)).' can be returned.');
                }

                if ($line->variant?->product?->is_serialized && $qty % Quantity::SCALE !== 0) {
                    throw new InvalidArgumentException("{$line->name_snapshot} is serialized: return whole pieces.");
                }

                $condition = $raw['condition'] instanceof ReturnCondition ? $raw['condition'] : ReturnCondition::from((string) $raw['condition']);
                $restockId = (int) ($raw['restock_location_id'] ?? 0) ?: $order->location_id;

                if (! $line->is_service && ! Location::query()->whereKey($restockId)->where('is_active', true)->exists()) {
                    throw new InvalidArgumentException("{$label}: choose an active location to restock.");
                }

                // The last piece of a line takes whatever value is left, so rounding never drifts.
                $amount = $line->returned_qty + $qty === $line->quantity
                    ? $line->line_total - (int) ($previousAmounts[$line->id] ?? 0)
                    : intdiv($line->line_total * $qty * 2 + $line->quantity, $line->quantity * 2);

                $prepared[] = compact('line', 'qty', 'condition', 'restockId', 'amount');
            }

            $total = array_sum(array_column($prepared, 'amount'));
            $location = Location::query()->findOrFail($order->location_id);
            $settings = CompanySetting::current();
            $seq = Counter::next(DocumentType::SalesReturn->value, "location:{$location->id}");

            $return = SalesReturn::query()->create([
                'number' => sprintf('%s-%s-%06d', $settings->prefixFor(DocumentType::SalesReturn), $location->code, $seq),
                'location_id' => $location->id,
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'outcome' => $outcome,
                'total' => $total,
                'settled_amount' => 0,
                'reason' => trim($reason),
                'created_by' => $actor->id,
                'approved_by' => $approver?->id,
                'business_date' => BusinessDay::current($location->id),
            ]);

            foreach ($prepared as $item) {
                $this->restock($return, $order, $item, $actor);
            }

            // What the customer paid beyond what they now owe is settled; the rest just lowers the balance.
            $kept = $order->paid_amount - $order->refunded_total;
            $order->returned_total += $total;
            $settle = max(0, min($total, $kept - $order->netTotal()));

            if ($settle > 0) {
                if ($outcome === ReturnOutcome::Refund) {
                    if (! $refundMethodId) {
                        throw new InvalidArgumentException('Choose how the refund is paid.');
                    }
                    $this->creditNotes->recordRefund([
                        'location_id' => $location->id,
                        'customer_id' => $order->customer_id,
                        'sales_return_id' => $return->id,
                        'payment_method_id' => $refundMethodId,
                        'amount' => $settle,
                        'reference' => $refundReference,
                        'reason' => "Return {$return->number}: ".trim($reason),
                        'approved_by' => $approver?->id,
                    ], $actor);
                } else {
                    $this->creditNotes->issue(
                        $location->id,
                        $order->customer_id,
                        $settle,
                        ($outcome === ReturnOutcome::Exchange ? 'Exchange' : 'Return')." {$return->number}: ".trim($reason),
                        $actor,
                        $order,
                        $return,
                    );
                }

                $order->refunded_total += $settle;
                $return->update(['settled_amount' => $settle]);
            }

            $this->payments->refresh($order);

            if ($order->customer) {
                $order->customer->total_spent = max(0, $order->customer->total_spent - $total);
                $order->customer->save();
            }

            AuditLog::record(
                'sales_return.created',
                $return,
                "Return {$return->number} on {$order->number}: ".Money::fromCents($total)->format()." ({$outcome->label()})",
                null,
                [
                    'total' => $total,
                    'settled' => $settle,
                    'outcome' => $outcome->value,
                    'approved_by' => $approver?->id,
                    'lines' => array_map(fn ($i) => [
                        'line_id' => $i['line']->id,
                        'quantity' => $i['qty'],
                        'condition' => $i['condition']->value,
                        'restock_location_id' => $i['restockId'],
                    ], $prepared),
                ],
                $actor,
            );

            return $return->fresh(['lines', 'creditNote', 'refund']);
        });
    }

    public function isLate(SalesOrder $order): bool
    {
        $days = (int) (CompanySetting::current()->return_days ?? 7);
        $soldAt = $order->confirmed_at ?? $order->created_at;

        return $soldAt !== null && now()->gt($soldAt->copy()->addDays($days)->endOfDay());
    }

    private function approveIfLate(SalesOrder $order, User $actor, ?int $approverId, ?string $pin): ?User
    {
        if (! $this->isLate($order)) {
            return null;
        }

        if ($actor->role->canOverride()) {
            return $actor;
        }

        return ManagerApproval::verify(
            $actor,
            $order->location_id,
            $approverId,
            $pin,
            'sales_return.late',
            $order,
            "a late return on {$order->number}",
        );
    }

    /**
     * @param  array{line: SalesOrderLine, qty: int, condition: ReturnCondition, restockId: int, amount: int}  $item
     */
    private function restock(SalesReturn $return, SalesOrder $order, array $item, User $actor): void
    {
        ['line' => $line, 'qty' => $qty, 'condition' => $condition, 'restockId' => $restockId] = $item;

        $unitId = $line->stock_unit_id;

        $return->lines()->create([
            'sales_order_line_id' => $line->id,
            'product_variant_id' => $line->product_variant_id,
            'stock_unit_id' => $unitId,
            'quantity' => $qty,
            'condition' => $condition,
            'restock_location_id' => $line->is_service ? null : $restockId,
            'amount' => $item['amount'],
        ]);

        $line->returned_qty += $qty;
        $line->save();

        if ($line->is_service || ! $line->product_variant_id) {
            return;
        }

        $note = "Return {$return->number} ({$condition->label()}) from {$order->number}";

        if ($unitId) {
            StockUnit::query()->whereKey($unitId)->lockForUpdate()->first()?->update([
                'location_id' => $restockId,
                'status' => StockUnitStatus::InStock,
                'condition' => $condition->stockUnitCondition(),
                'sold_reference_type' => null,
                'sold_reference_id' => null,
            ]);
        }

        $this->stock->move([
            'product_variant_id' => $line->product_variant_id,
            'location_id' => $restockId,
            'type' => MovementType::SaleReturn,
            'quantity' => $qty,
            'unit_cost' => $line->unit_cost_snapshot ?: null,
            'reference' => $return,
            'stock_unit_id' => $unitId,
            'user_id' => $actor->id,
            'note' => $note,
        ]);

        // Damaged goods come back on the ledger, then straight out as a write-off: never sellable stock.
        if ($condition === ReturnCondition::Damaged) {
            $this->stock->move([
                'product_variant_id' => $line->product_variant_id,
                'location_id' => $restockId,
                'type' => MovementType::Damage,
                'quantity' => -$qty,
                'unit_cost' => $line->unit_cost_snapshot ?: null,
                'reference' => $return,
                'stock_unit_id' => $unitId,
                'user_id' => $actor->id,
                'note' => $note,
                'allow_negative' => true,
            ]);

            if ($unitId) {
                StockUnit::query()->whereKey($unitId)->update(['status' => StockUnitStatus::Damaged->value]);
            }
        }
    }
}
