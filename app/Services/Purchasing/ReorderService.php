<?php

namespace App\Services\Purchasing;

use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes reorder suggestions for variants whose available stock + open PO quantity
 * at a location falls below the variant's reorder_level, grouped by preferred supplier.
 */
class ReorderService
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
    ) {}

    /**
     * Return reorder suggestions for one or more locations, grouped by preferred supplier and location.
     *
     * @param  array<int, int>|null  $locationIds
     * @return array<int, array{
     *     group_key: string,
     *     supplier_id: int|null,
     *     supplier_name: string,
     *     location_id: int,
     *     location_name: string,
     *     location_code: string,
     *     items: array<int, array{
     *         product_variant_id: int,
     *         product_id: int,
     *         sku: string,
     *         product_name: string,
     *         variant_name: string|null,
     *         supplier_sku: string|null,
     *         on_hand: int,
     *         reserved: int,
     *         available: int,
     *         open_po_qty: int,
     *         projected_qty: int,
     *         reorder_level: int,
     *         reorder_qty: int,
     *         suggested_qty: int,
     *         unit_cost: int,
     *     }>,
     * }>
     */
    public function suggestions(?array $locationIds = null): array
    {
        $locationsQuery = Location::query()->where('is_active', true)->orderBy('id');
        if ($locationIds !== null) {
            $locationsQuery->whereIn('id', $locationIds);
        }
        /** @var Collection<int, Location> $locations */
        $locations = $locationsQuery->get(['id', 'name', 'code']);

        if ($locations->isEmpty()) {
            return [];
        }

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->whereHas('product', fn ($q) => $q
                ->where('is_active', true)
                ->where('type', ProductType::Stock->value))
            ->with(['product:id,name,type,is_serialized,is_active'])
            ->orderBy('sku')
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        $variantIds = $variants->pluck('id')->all();
        $productIds = $variants->pluck('product_id')->unique()->values()->all();
        $locIds = $locations->pluck('id')->all();

        // Load stock levels keyed by "{location_id}:{variant_id}"
        $levels = StockLevel::query()
            ->whereIn('location_id', $locIds)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy(fn (StockLevel $l) => "{$l->location_id}:{$l->product_variant_id}");

        // Load open PO quantities keyed by "{location_id}:{variant_id}"
        $openStatuses = [
            PurchaseOrderStatus::Draft->value,
            PurchaseOrderStatus::Sent->value,
            PurchaseOrderStatus::PartiallyReceived->value,
        ];

        $openPoRows = DB::table('purchase_order_lines as pol')
            ->join('purchase_orders as po', 'po.id', '=', 'pol.purchase_order_id')
            ->whereIn('po.location_id', $locIds)
            ->whereIn('po.status', $openStatuses)
            ->whereIn('pol.product_variant_id', $variantIds)
            ->whereRaw('pol.ordered_qty > pol.received_qty')
            ->groupBy('po.location_id', 'pol.product_variant_id')
            ->selectRaw('po.location_id, pol.product_variant_id, SUM(pol.ordered_qty - pol.received_qty) as open_qty')
            ->get();

        $openPoByKey = [];
        foreach ($openPoRows as $row) {
            $openPoByKey["{$row->location_id}:{$row->product_variant_id}"] = (int) $row->open_qty;
        }

        // Preferred supplier per product_variant_id
        $supplierPivots = DB::table('product_supplier as ps')
            ->leftJoin('suppliers as s', 's.id', '=', 'ps.supplier_id')
            ->whereIn('ps.product_variant_id', $variantIds)
            ->orderByDesc('ps.is_preferred')
            ->orderBy('ps.id')
            ->select([
                'ps.product_variant_id',
                'ps.supplier_id',
                'ps.supplier_sku',
                'ps.last_cost',
                's.name as linked_supplier_name',
                's.is_active as supplier_active',
            ])
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->first());

        $groups = [];

        foreach ($locations as $location) {
            foreach ($variants as $variant) {
                $key = "{$location->id}:{$variant->id}";
                /** @var StockLevel|null $level */
                $level = $levels->get($key);

                $onHand = $level ? (int) $level->on_hand : 0;
                $reserved = $level ? (int) $level->reserved : 0;
                $available = max(0, $onHand - $reserved);
                $openPoQty = $openPoByKey[$key] ?? 0;
                $projected = $available + $openPoQty;

                if ($projected >= $variant->reorder_level) {
                    continue;
                }

                $shortfall = $variant->reorder_level - $projected;
                $suggestedQty = max((int) $variant->reorder_qty, $shortfall);

                if ($variant->product?->is_serialized && ($suggestedQty % 1000 !== 0)) {
                    $suggestedQty = (int) (ceil($suggestedQty / 1000) * 1000);
                }

                $pivot = $supplierPivots->get($variant->id);
                $supplierId = $pivot && $pivot->supplier_id ? (int) $pivot->supplier_id : null;
                $supplierName = $pivot
                    ? ($pivot->linked_supplier_name ?? 'Unassigned Supplier')
                    : 'Unassigned Supplier';
                $supplierSku = $pivot->supplier_sku ?? null;

                $unitCost = (int) ($pivot?->last_cost ?: ($variant->last_cost ?: $variant->average_cost));

                $groupKey = ($supplierId ?? 'none').':'.$location->id;

                if (! isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'group_key' => $groupKey,
                        'supplier_id' => $supplierId,
                        'supplier_name' => $supplierName,
                        'location_id' => $location->id,
                        'location_name' => $location->name,
                        'location_code' => $location->code,
                        'items' => [],
                    ];
                }

                $groups[$groupKey]['items'][] = [
                    'product_variant_id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'sku' => $variant->sku,
                    'product_name' => $variant->product?->name ?? $variant->sku,
                    'variant_name' => $variant->name,
                    'supplier_sku' => $supplierSku,
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'available' => $available,
                    'open_po_qty' => $openPoQty,
                    'projected_qty' => $projected,
                    'reorder_level' => (int) $variant->reorder_level,
                    'reorder_qty' => (int) $variant->reorder_qty,
                    'suggested_qty' => $suggestedQty,
                    'unit_cost' => $unitCost,
                ];
            }
        }

        return array_values($groups);
    }

    /**
     * Create a draft PurchaseOrder from reorder suggestion lines for a supplier and location.
     *
     * @param  array{
     *     supplier_id: int,
     *     location_id: int,
     *     expected_date?: string|null,
     *     notes?: string|null,
     *     lines: array<int, array{
     *         product_variant_id: int,
     *         ordered_qty: int,
     *         unit_cost: int,
     *         supplier_sku?: string|null,
     *     }>,
     * }  $data
     */
    public function createPurchaseOrder(array $data, User $actor): PurchaseOrder
    {
        $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);

        return $this->purchaseOrderService->save([
            'supplier_id' => $supplier->id,
            'location_id' => (int) $data['location_id'],
            'expected_date' => $data['expected_date'] ?? null,
            'notes' => $data['notes'] ?? 'Created from reorder suggestions',
            'lines' => $data['lines'],
        ], $actor);
    }
}
