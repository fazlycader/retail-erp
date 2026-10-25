<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Mail\PurchaseOrderMail;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BasisPoints;
use App\Support\Counter;
use App\Support\SyncVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Manages purchase orders:
 * draft → sent → partially_received → received → closed | cancelled
 */
class PurchaseOrderService
{
    /**
     * Create or update a purchase order.
     *
     * @param  array{
     *     supplier_id: int,
     *     location_id: int,
     *     order_date?: string|null,
     *     expected_date?: string|null,
     *     notes?: string|null,
     *     lines: array<int, array{
     *         id?: int|null,
     *         product_variant_id: int,
     *         supplier_sku?: string|null,
     *         ordered_qty: int,
     *         unit_cost: int,
     *         discount_cents?: int|null,
     *         tax_rate_bp?: int|null,
     *         sales_order_line_id?: int|null,
     *         note?: string|null,
     *     }>,
     * }  $data
     */
    public function save(array $data, User $actor, ?PurchaseOrder $po = null): PurchaseOrder
    {
        if (empty($data['lines'])) {
            throw new InvalidArgumentException('A purchase order requires at least one line.');
        }

        if ($po && ! $po->status->canEdit()) {
            throw new InvalidArgumentException("Purchase order [{$po->number}] cannot be edited in status [{$po->status->value}].");
        }

        return DB::transaction(function () use ($data, $actor, $po) {
            $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);
            $location = Location::query()->findOrFail((int) $data['location_id']);

            $variantIds = array_values(array_unique(array_map(fn ($l) => (int) $l['product_variant_id'], $data['lines'])));
            $variants = ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->get()->keyBy('id');

            $subtotal = 0;
            $discountTotal = 0;
            $taxTotal = 0;
            $totalAmount = 0;
            $preparedLines = [];

            foreach ($data['lines'] as $index => $line) {
                $variantId = (int) $line['product_variant_id'];
                $variant = $variants->get($variantId);
                if (! $variant) {
                    throw new InvalidArgumentException("Variant [{$variantId}] not found.");
                }

                $orderedQty = (int) $line['ordered_qty'];
                if ($orderedQty <= 0) {
                    throw new InvalidArgumentException('Ordered quantity on line #'.($index + 1).' must be greater than zero.');
                }

                if ($variant->product && $variant->product->is_serialized && ($orderedQty % 1000 !== 0)) {
                    throw new InvalidArgumentException("Serialized item [{$variant->sku}] must be ordered in whole units.");
                }

                $unitCost = max(0, (int) $line['unit_cost']);
                $discountCents = max(0, (int) ($line['discount_cents'] ?? 0));
                $taxRateBp = max(0, (int) ($line['tax_rate_bp'] ?? 0));

                // orderedQty is thousandths (1000 = 1 unit)
                $grossLine = intdiv($orderedQty * $unitCost + 500, 1000);
                if ($discountCents > $grossLine) {
                    throw new InvalidArgumentException("Discount cannot exceed gross line amount on [{$variant->sku}].");
                }

                $netLine = $grossLine - $discountCents;
                $taxCents = BasisPoints::of($netLine, $taxRateBp);
                $lineTotal = $netLine + $taxCents;

                $subtotal += $grossLine;
                $discountTotal += $discountCents;
                $taxTotal += $taxCents;
                $totalAmount += $lineTotal;

                $preparedLines[] = [
                    'product_variant_id' => $variantId,
                    'supplier_sku' => isset($line['supplier_sku']) && trim((string) $line['supplier_sku']) !== ''
                        ? trim((string) $line['supplier_sku'])
                        : null,
                    'ordered_qty' => $orderedQty,
                    'unit_cost' => $unitCost,
                    'discount_cents' => $discountCents,
                    'tax_rate_bp' => $taxRateBp,
                    'tax_cents' => $taxCents,
                    'line_total' => $lineTotal,
                    'sales_order_line_id' => ! empty($line['sales_order_line_id']) ? (int) $line['sales_order_line_id'] : null,
                    'note' => isset($line['note']) && trim((string) $line['note']) !== '' ? trim((string) $line['note']) : null,
                ];
            }

            if (! $po) {
                $prefix = CompanySetting::current()->prefixFor(DocumentType::PurchaseOrder);
                $seq = Counter::next(DocumentType::PurchaseOrder->value, "location:{$location->id}");
                $number = sprintf('%s-%s-%06d', $prefix, $location->code, $seq);

                $po = PurchaseOrder::query()->create([
                    'number' => $number,
                    'supplier_id' => $supplier->id,
                    'location_id' => $location->id,
                    'status' => PurchaseOrderStatus::Draft,
                    'order_date' => ! empty($data['order_date']) ? $data['order_date'] : now()->toDateString(),
                    'expected_date' => ! empty($data['expected_date']) ? $data['expected_date'] : null,
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'total' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'note' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                    'created_by' => $actor->id,
                    'sync_version' => 0,
                ]);
            } else {
                $po->update([
                    'supplier_id' => $supplier->id,
                    'location_id' => $location->id,
                    'order_date' => ! empty($data['order_date']) ? $data['order_date'] : $po->order_date,
                    'expected_date' => ! empty($data['expected_date']) ? $data['expected_date'] : null,
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'tax_total' => $taxTotal,
                    'total' => $totalAmount,
                    'total_amount' => $totalAmount,
                    'note' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                ]);
                $po->lines()->delete();
            }

            foreach ($preparedLines as $lineRow) {
                $po->lines()->create($lineRow);
            }

            SyncVersion::bump($po);

            AuditLog::record(
                $po->wasRecentlyCreated ? 'purchase_order.created' : 'purchase_order.updated',
                $po,
                "Purchase order {$po->number}",
                null,
                [
                    'number' => $po->number,
                    'supplier_id' => $po->supplier_id,
                    'location_id' => $po->location_id,
                    'total_amount' => $po->total_amount,
                    'lines_count' => count($preparedLines),
                ],
                $actor,
            );

            return $po->fresh(['supplier', 'location', 'lines.variant.product', 'createdBy']);
        });
    }

    /**
     * Mark a draft PO as sent and optionally queue the PO email with PDF attachment to the supplier.
     */
    public function send(PurchaseOrder $po, User $actor, bool $emailSupplier = true): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $emailSupplier) {
            $po = PurchaseOrder::query()
                ->with(['supplier', 'location', 'lines.variant.product'])
                ->lockForUpdate()
                ->findOrFail($po->id);

            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent], true)) {
                throw new InvalidArgumentException("Purchase order [{$po->number}] cannot be sent from status [{$po->status->value}].");
            }

            if ($po->status === PurchaseOrderStatus::Draft) {
                $po->status = PurchaseOrderStatus::Sent;
                $po->sent_by = $actor->id;
                $po->sent_at = now();
                $po->save();
                SyncVersion::bump($po);
            }

            $emailedTo = null;
            if ($emailSupplier && $po->supplier && $po->supplier->email) {
                $emailedTo = $po->supplier->email;
                Mail::to($emailedTo)->queue(new PurchaseOrderMail($po->id));
            }

            AuditLog::record(
                'purchase_order.sent',
                $po,
                "Sent purchase order {$po->number}",
                null,
                [
                    'number' => $po->number,
                    'status' => $po->status->value,
                    'emailed_to' => $emailedTo,
                ],
                $actor,
            );

            return $po->fresh(['supplier', 'location', 'lines.variant.product']);
        });
    }

    /**
     * Close a PO (no further receipts expected).
     */
    public function close(PurchaseOrder $po, User $actor, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);

            if (! $po->status->canClose()) {
                throw new InvalidArgumentException("Purchase order [{$po->number}] cannot be closed from status [{$po->status->value}].");
            }

            $oldStatus = $po->status->value;
            $po->status = PurchaseOrderStatus::Closed;
            $po->closed_by = $actor->id;
            $po->closed_at = now();
            $po->save();

            SyncVersion::bump($po);

            AuditLog::record(
                'purchase_order.closed',
                $po,
                "Closed purchase order {$po->number}",
                ['status' => $oldStatus],
                ['status' => $po->status->value, 'reason' => $reason],
                $actor,
            );

            return $po->fresh(['supplier', 'location', 'lines.variant.product']);
        });
    }

    /**
     * Cancel a PO that has not had any stock received against it.
     */
    public function cancel(PurchaseOrder $po, User $actor, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $po = PurchaseOrder::query()->with('lines')->lockForUpdate()->findOrFail($po->id);

            if (! $po->status->canCancel()) {
                throw new InvalidArgumentException("Purchase order [{$po->number}] cannot be cancelled from status [{$po->status->value}].");
            }

            $anyReceived = $po->lines->contains(fn ($l) => $l->received_qty > 0);
            if ($anyReceived) {
                throw new InvalidArgumentException("Purchase order [{$po->number}] already has received items; close it instead of cancelling.");
            }

            $oldStatus = $po->status->value;
            $po->status = PurchaseOrderStatus::Cancelled;
            $po->cancelled_by = $actor->id;
            $po->cancelled_at = now();
            $po->cancel_reason = $reason ? trim($reason) : null;
            $po->save();

            SyncVersion::bump($po);

            AuditLog::record(
                'purchase_order.cancelled',
                $po,
                "Cancelled purchase order {$po->number}",
                ['status' => $oldStatus],
                ['status' => $po->status->value, 'cancel_reason' => $po->cancel_reason],
                $actor,
            );

            return $po->fresh(['supplier', 'location', 'lines.variant.product']);
        });
    }
}
