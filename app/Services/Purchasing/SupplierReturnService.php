<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\MovementType;
use App\Enums\StockUnitStatus;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\Quantity;
use App\Support\SyncVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Supplier return service: returns stock to a supplier (MovementType::SupplierReturn)
 * with reason and expected credit amount.
 */
class SupplierReturnService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array{
     *     supplier_id: int,
     *     location_id: int,
     *     goods_receipt_id?: int|null,
     *     returned_at?: string|null,
     *     reason: string,
     *     notes?: string|null,
     *     lines: array<int, array{
     *         product_variant_id: int,
     *         quantity: int,
     *         unit_cost?: int|null,
     *         stock_unit_id?: int|null,
     *         serial_number?: string|null,
     *         reason?: string|null,
     *     }>,
     * }  $data
     */
    public function create(array $data, User $actor): SupplierReturn
    {
        if (empty($data['lines'])) {
            throw new InvalidArgumentException('A supplier return requires at least one line.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('A return reason is required.');
        }

        return DB::transaction(function () use ($data, $reason, $actor) {
            $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);
            $location = Location::query()->findOrFail((int) $data['location_id']);

            $grnId = ! empty($data['goods_receipt_id']) ? (int) $data['goods_receipt_id'] : null;
            if ($grnId) {
                $grn = GoodsReceipt::query()->findOrFail($grnId);
                if ($grn->supplier_id !== $supplier->id) {
                    throw new InvalidArgumentException('Selected GRN belongs to a different supplier.');
                }
            }

            $variantIds = array_values(array_unique(array_map(fn ($l) => (int) $l['product_variant_id'], $data['lines'])));
            sort($variantIds);

            $variants = ProductVariant::query()
                ->withTrashed()
                ->with('product')
                ->whereIn('id', $variantIds)
                ->get()
                ->keyBy('id');

            $preparedLines = [];
            $creditExpected = 0;

            foreach ($data['lines'] as $rawLine) {
                $qty = (int) ($rawLine['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Return quantity must be greater than zero.');
                }

                $variantId = (int) $rawLine['product_variant_id'];
                $variant = $variants->get($variantId);
                if (! $variant) {
                    throw new InvalidArgumentException("Variant [{$variantId}] not found.");
                }

                if ($variant->product?->is_serialized && ($qty % 1000 !== 0)) {
                    throw new InvalidArgumentException("Serialized item [{$variant->sku}] must be returned in whole units.");
                }

                $unitCost = isset($rawLine['unit_cost']) && $rawLine['unit_cost'] !== null
                    ? max(0, (int) $rawLine['unit_cost'])
                    : ($variant->last_cost ?: $variant->average_cost);

                $lineTotal = Quantity::times($qty, $unitCost);
                $creditExpected += $lineTotal;

                $preparedLines[] = [
                    'variant' => $variant,
                    'product_variant_id' => $variantId,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'stock_unit_id' => ! empty($rawLine['stock_unit_id']) ? (int) $rawLine['stock_unit_id'] : null,
                    'serial_number' => isset($rawLine['serial_number']) && trim((string) $rawLine['serial_number']) !== ''
                        ? trim((string) $rawLine['serial_number'])
                        : null,
                    'reason' => isset($rawLine['reason']) && trim((string) $rawLine['reason']) !== ''
                        ? trim((string) $rawLine['reason'])
                        : null,
                ];
            }

            $prefix = CompanySetting::current()->prefixFor(DocumentType::SupplierReturn);
            $seq = Counter::next(DocumentType::SupplierReturn->value, "location:{$location->id}");
            $number = sprintf('%s-%s-%06d', $prefix, $location->code, $seq);
            $businessDate = BusinessDay::current($location->id);

            $supplierReturn = SupplierReturn::query()->create([
                'number' => $number,
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'goods_receipt_id' => $grnId,
                'business_date' => $businessDate,
                'returned_at' => ! empty($data['returned_at']) ? $data['returned_at'] : now()->toDateString(),
                'reason' => $reason,
                'credit_expected' => $creditExpected,
                'total' => $creditExpected,
                'note' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'created_by' => $actor->id,
                'sync_version' => 0,
            ]);

            $movementEntries = [];

            foreach ($preparedLines as $line) {
                $stockUnitId = $line['stock_unit_id'];

                if ($line['variant']->product?->is_serialized && ($stockUnitId || $line['serial_number'])) {
                    $unit = $stockUnitId
                        ? StockUnit::query()->whereKey($stockUnitId)->lockForUpdate()->first()
                        : StockUnit::query()->where('serial_number', $line['serial_number'])->lockForUpdate()->first();

                    if (! $unit || $unit->product_variant_id !== $line['product_variant_id']) {
                        throw new InvalidArgumentException("Serialized unit not found for [{$line['variant']->sku}].");
                    }

                    $unit->update([
                        'status' => StockUnitStatus::Returned,
                        'location_id' => null,
                    ]);
                    $stockUnitId = $unit->id;
                }

                $supplierReturn->lines()->create([
                    'product_variant_id' => $line['product_variant_id'],
                    'stock_unit_id' => $stockUnitId,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $line['line_total'],
                    'reason' => $line['reason'] ?? $reason,
                ]);

                $movementEntries[] = [
                    'product_variant_id' => $line['product_variant_id'],
                    'location_id' => $location->id,
                    'type' => MovementType::SupplierReturn,
                    'quantity' => -$line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'reference' => $supplierReturn,
                    'stock_unit_id' => $stockUnitId,
                    'user_id' => $actor->id,
                    'business_date' => $businessDate,
                    'note' => "Supplier return {$supplierReturn->number}: ".($line['reason'] ?? $reason),
                ];
            }

            $this->stock->moveMany($movementEntries);

            SyncVersion::bump($supplierReturn);

            AuditLog::record(
                'supplier_return.created',
                $supplierReturn,
                "Supplier return {$supplierReturn->number} to {$supplier->name}",
                null,
                [
                    'number' => $supplierReturn->number,
                    'supplier_id' => $supplier->id,
                    'credit_expected' => $creditExpected,
                ],
                $actor,
            );

            return $supplierReturn->fresh(['supplier', 'location', 'goodsReceipt', 'lines.variant.product', 'createdBy']);
        });
    }
}
