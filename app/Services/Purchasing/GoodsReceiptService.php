<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Production\ProductionService;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\ManagerApproval;
use App\Support\Quantity;
use App\Support\SyncVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Goods Receipt Note (GRN) service.
 *
 * Receives stock against a PurchaseOrder or directly from a Supplier into a Location:
 * - Posts MovementType::PurchaseReceipt movements (updating weighted average_cost and last_cost).
 * - Posts MovementType::Damage movements for any damaged-on-arrival quantity.
 * - Creates StockUnit rows for serialized items with their own unit_cost.
 * - Enforces manager approval (ManagerApproval) when over-receiving beyond a PO.
 * - Updates PO line received_qty and transitions PO status (partially_received / received).
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array{
     *     purchase_order_id?: int|null,
     *     supplier_id?: int|null,
     *     location_id?: int|null,
     *     supplier_delivery_note?: string|null,
     *     received_at?: string|null,
     *     notes?: string|null,
     *     approver_id?: int|null,
     *     pin?: string|null,
     *     lines: array<int, array{
     *         purchase_order_line_id?: int|null,
     *         product_variant_id: int,
     *         received_qty: int,
     *         damaged_qty?: int|null,
     *         unit_cost: int,
     *         serials?: array<int, string>|string|null,
     *         note?: string|null,
     *     }>,
     * }  $data
     */
    public function receive(array $data, User $actor): GoodsReceipt
    {
        if (empty($data['lines'])) {
            throw new InvalidArgumentException('A goods receipt requires at least one line with received quantity.');
        }

        return DB::transaction(function () use ($data, $actor) {
            /** @var PurchaseOrder|null $po */
            $po = null;
            /** @var Collection<int, PurchaseOrderLine> $poLinesById */
            $poLinesById = collect();

            if (! empty($data['purchase_order_id'])) {
                $po = PurchaseOrder::query()
                    ->with('lines')
                    ->lockForUpdate()
                    ->findOrFail((int) $data['purchase_order_id']);

                if (! $po->status->canReceive()) {
                    throw new InvalidArgumentException("Purchase order [{$po->number}] cannot receive goods in status [{$po->status->value}].");
                }

                $poLinesById = $po->lines->keyBy('id');
            }

            $supplierId = (int) ($data['supplier_id'] ?? $po?->supplier_id ?? 0);
            $locationId = (int) ($data['location_id'] ?? $po?->location_id ?? 0);

            if (! $supplierId || ! $locationId) {
                throw new InvalidArgumentException('Supplier and destination location are required.');
            }

            $supplier = Supplier::query()->findOrFail($supplierId);
            $location = Location::query()->findOrFail($locationId);

            if ($po && $po->supplier_id !== $supplier->id) {
                throw new InvalidArgumentException('Goods receipt supplier must match the purchase order supplier.');
            }

            $variantIds = array_values(array_unique(array_map(fn ($l) => (int) $l['product_variant_id'], $data['lines'])));
            sort($variantIds);

            $variants = ProductVariant::query()
                ->withTrashed()
                ->with('product')
                ->whereIn('id', $variantIds)
                ->get()
                ->keyBy('id');

            $overReceived = false;
            $preparedLines = [];
            $totalAmount = 0;
            $allSerialsInGrn = [];

            foreach ($data['lines'] as $index => $rawLine) {
                $receivedQty = (int) ($rawLine['received_qty'] ?? 0);
                if ($receivedQty <= 0) {
                    continue;
                }

                $variantId = (int) $rawLine['product_variant_id'];
                $variant = $variants->get($variantId);
                if (! $variant) {
                    throw new InvalidArgumentException("Variant [{$variantId}] not found.");
                }

                $damagedQty = max(0, (int) ($rawLine['damaged_qty'] ?? 0));
                if ($damagedQty > $receivedQty) {
                    throw new InvalidArgumentException("Damaged quantity cannot exceed received quantity for [{$variant->sku}].");
                }

                $unitCost = max(0, (int) $rawLine['unit_cost']);
                $isSerialized = (bool) $variant->product?->is_serialized;
                $serials = $this->normalizeSerials($rawLine['serials'] ?? null);

                if ($isSerialized) {
                    if ($receivedQty % 1000 !== 0 || $damagedQty % 1000 !== 0) {
                        throw new InvalidArgumentException("Serialized item [{$variant->sku}] must be received in whole units.");
                    }

                    $expectedUnits = intdiv($receivedQty, 1000);
                    if (count($serials) !== $expectedUnits) {
                        throw new InvalidArgumentException("Serialized item [{$variant->sku}] requires {$expectedUnits} serial number(s); ".count($serials).' given.');
                    }

                    foreach ($serials as $sn) {
                        if (in_array($sn, $allSerialsInGrn, true)) {
                            throw new InvalidArgumentException("Duplicate serial number [{$sn}] in the same receipt.");
                        }
                        $allSerialsInGrn[] = $sn;
                    }
                }

                /** @var PurchaseOrderLine|null $poLine */
                $poLine = null;
                if ($po) {
                    if (! empty($rawLine['purchase_order_line_id'])) {
                        $poLine = $poLinesById->get((int) $rawLine['purchase_order_line_id']);
                        if (! $poLine || $poLine->product_variant_id !== $variantId) {
                            throw new InvalidArgumentException("Invalid purchase order line for [{$variant->sku}].");
                        }
                    } else {
                        $poLine = $po->lines->firstWhere('product_variant_id', $variantId);
                    }

                    if (! $poLine || ($poLine->received_qty + $receivedQty > $poLine->ordered_qty)) {
                        $overReceived = true;
                    }
                }

                $lineTotal = Quantity::times($receivedQty, $unitCost);
                $totalAmount += $lineTotal;

                $preparedLines[] = [
                    'variant' => $variant,
                    'product_variant_id' => $variantId,
                    'purchase_order_line' => $poLine,
                    'purchase_order_line_id' => $poLine?->id,
                    'received_qty' => $receivedQty,
                    'damaged_qty' => $damagedQty,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'is_serialized' => $isSerialized,
                    'serials' => $serials,
                    'note' => isset($rawLine['note']) && trim((string) $rawLine['note']) !== '' ? trim((string) $rawLine['note']) : null,
                ];
            }

            if (empty($preparedLines)) {
                throw new InvalidArgumentException('A goods receipt requires at least one line with a positive received quantity.');
            }

            if (! empty($allSerialsInGrn)) {
                $existingSerial = StockUnit::query()->whereIn('serial_number', $allSerialsInGrn)->value('serial_number');
                if ($existingSerial) {
                    throw new InvalidArgumentException("Serial number [{$existingSerial}] already exists in stock.");
                }
            }

            $approvedBy = null;
            if ($overReceived) {
                $approver = $this->verifyOverReceiveApproval(
                    $actor,
                    $locationId,
                    isset($data['approver_id']) ? (int) $data['approver_id'] : null,
                    $data['pin'] ?? null,
                    $po,
                );
                $approvedBy = $approver->id;
            }

            $prefix = CompanySetting::current()->prefixFor(DocumentType::Grn);
            $seq = Counter::next(DocumentType::Grn->value, "location:{$location->id}");
            $number = sprintf('%s-%s-%06d', $prefix, $location->code, $seq);

            $businessDate = BusinessDay::current($locationId);

            $grn = GoodsReceipt::query()->create([
                'number' => $number,
                'purchase_order_id' => $po?->id,
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'supplier_delivery_note' => isset($data['supplier_delivery_note']) && trim((string) $data['supplier_delivery_note']) !== ''
                    ? trim((string) $data['supplier_delivery_note'])
                    : null,
                'business_date' => $businessDate,
                'received_at' => ! empty($data['received_at']) ? $data['received_at'] : now(),
                'subtotal' => $totalAmount,
                'tax_total' => 0,
                'total' => $totalAmount,
                'total_amount' => $totalAmount,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'received_by' => $actor->id,
                'approved_by' => $approvedBy,
                'sync_version' => 0,
            ]);

            $movementEntries = [];

            foreach ($preparedLines as $line) {
                /** @var ProductVariant $variant */
                $variant = $line['variant'];

                $grn->lines()->create([
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'received_qty' => $line['received_qty'],
                    'damaged_qty' => $line['damaged_qty'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $line['line_total'],
                    'serials' => $line['is_serialized'] ? $line['serials'] : null,
                    'note' => $line['note'],
                ]);

                if ($line['is_serialized']) {
                    $totalUnits = count($line['serials']);
                    $damagedUnits = intdiv($line['damaged_qty'], 1000);
                    $goodUnits = $totalUnits - $damagedUnits;

                    foreach ($line['serials'] as $idx => $serialNumber) {
                        $isDamagedUnit = $idx >= $goodUnits;

                        $unit = StockUnit::query()->create([
                            'serial_number' => $serialNumber,
                            'product_variant_id' => $variant->id,
                            'location_id' => $isDamagedUnit ? null : $location->id,
                            'status' => $isDamagedUnit ? StockUnitStatus::Damaged : StockUnitStatus::InStock,
                            'condition' => $isDamagedUnit ? StockUnitCondition::Damaged : StockUnitCondition::New,
                            'unit_cost' => $line['unit_cost'],
                            'received_at' => now(),
                            'note' => $line['note'] ?? "Received on {$grn->number}",
                        ]);

                        $movementEntries[] = [
                            'product_variant_id' => $variant->id,
                            'location_id' => $location->id,
                            'type' => MovementType::PurchaseReceipt,
                            'quantity' => 1000,
                            'cost_qty' => $idx === 0 ? $line['received_qty'] : 0,
                            'unit_cost' => $line['unit_cost'],
                            'reference' => $grn,
                            'stock_unit_id' => $unit->id,
                            'user_id' => $actor->id,
                            'business_date' => $businessDate,
                            'note' => "GRN {$grn->number} ({$serialNumber})",
                        ];

                        if ($isDamagedUnit) {
                            $movementEntries[] = [
                                'product_variant_id' => $variant->id,
                                'location_id' => $location->id,
                                'type' => MovementType::Damage,
                                'quantity' => -1000,
                                'unit_cost' => $line['unit_cost'],
                                'reference' => $grn,
                                'stock_unit_id' => $unit->id,
                                'user_id' => $actor->id,
                                'business_date' => $businessDate,
                                'note' => "Damaged on arrival — GRN {$grn->number} ({$serialNumber})",
                            ];
                        }
                    }
                } else {
                    $movementEntries[] = [
                        'product_variant_id' => $variant->id,
                        'location_id' => $location->id,
                        'type' => MovementType::PurchaseReceipt,
                        'quantity' => $line['received_qty'],
                        'unit_cost' => $line['unit_cost'],
                        'reference' => $grn,
                        'user_id' => $actor->id,
                        'business_date' => $businessDate,
                        'note' => $line['note'] ?? "GRN {$grn->number}",
                    ];

                    if ($line['damaged_qty'] > 0) {
                        $movementEntries[] = [
                            'product_variant_id' => $variant->id,
                            'location_id' => $location->id,
                            'type' => MovementType::Damage,
                            'quantity' => -$line['damaged_qty'],
                            'unit_cost' => $line['unit_cost'],
                            'reference' => $grn,
                            'user_id' => $actor->id,
                            'business_date' => $businessDate,
                            'note' => "Damaged on arrival — GRN {$grn->number}",
                        ];
                    }
                }

                // Update PO line received_qty
                if ($line['purchase_order_line']) {
                    /** @var PurchaseOrderLine $poLine */
                    $poLine = $line['purchase_order_line'];
                    $poLine->received_qty += $line['received_qty'];
                    $poLine->save();
                }

                // Update product_supplier last_cost
                $existingPivot = DB::table('product_supplier')
                    ->where('product_variant_id', $variant->id)
                    ->where('supplier_id', $supplier->id)
                    ->first();

                if ($existingPivot) {
                    DB::table('product_supplier')
                        ->where('id', $existingPivot->id)
                        ->update([
                            'last_cost' => $line['unit_cost'],
                            'updated_at' => now(),
                        ]);
                } else {
                    $hasPreferred = DB::table('product_supplier')
                        ->where('product_variant_id', $variant->id)
                        ->where('is_preferred', true)
                        ->exists();

                    DB::table('product_supplier')->insert([
                        'product_variant_id' => $variant->id,
                        'supplier_id' => $supplier->id,
                        'supplier_sku' => $line['purchase_order_line']?->supplier_sku,
                        'last_cost' => $line['unit_cost'],
                        'is_preferred' => ! $hasPreferred,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->stock->moveMany($movementEntries);

            // Outsourced custom pieces: reserve what arrived for the customer's order (Step 11).
            if ($po) {
                app(ProductionService::class)->goodsReceived($grn, $actor);
            }

            if ($po) {
                $po->load('lines');
                $allReceived = $po->lines->every(fn (PurchaseOrderLine $l) => $l->received_qty >= $l->ordered_qty);
                $anyReceived = $po->lines->contains(fn (PurchaseOrderLine $l) => $l->received_qty > 0);

                $newStatus = $allReceived
                    ? PurchaseOrderStatus::Received
                    : ($anyReceived ? PurchaseOrderStatus::PartiallyReceived : $po->status);

                if ($newStatus !== $po->status) {
                    $po->status = $newStatus;
                    $po->save();
                }

                SyncVersion::bump($po);
            }

            SyncVersion::bump($grn);

            AuditLog::record(
                'goods_receipt.created',
                $grn,
                "Received GRN {$grn->number}".($po ? " against {$po->number}" : ''),
                null,
                [
                    'number' => $grn->number,
                    'purchase_order_id' => $po?->id,
                    'supplier_id' => $supplier->id,
                    'total_amount' => $grn->total_amount,
                    'over_received' => $overReceived,
                    'approved_by' => $approvedBy,
                ],
                $actor,
            );

            return $grn->fresh(['supplier', 'location', 'purchaseOrder', 'lines.variant.product', 'receivedBy', 'approvedBy']);
        });
    }

    /**
     * @param  array<int, string>|string|null  $raw
     * @return array<int, string>
     */
    private function normalizeSerials(array|string|null $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        } else {
            $parts = $raw;
        }

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $parts), fn ($s) => $s !== ''));
    }

    private function verifyOverReceiveApproval(
        User $actor,
        int $locationId,
        ?int $approverId,
        ?string $pin,
        ?PurchaseOrder $po,
    ): User {
        $what = 'over-receiving beyond purchase order'.($po ? " {$po->number}" : '');

        if ($approverId === $actor->id && $actor->role->canOverride() && $actor->is_active && $actor->worksAt($locationId) && filled($pin)) {
            if ($actor->attemptPin($pin, $what)) {
                return $actor;
            }
        }

        return ManagerApproval::verify($actor, $locationId, $approverId, $pin, 'goods_receipt.over_receive', $po, $what);
    }
}
