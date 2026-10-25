<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductType;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StockUnit;
use App\Services\Catalog\CategoryTree;
use App\Support\CurrentLocation;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function index(Request $request, CurrentLocation $currentLocation, CategoryTree $tree): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'low_stock' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code', 'type']);

        $activeLocId = ! empty($filters['location_id'])
            ? (int) $filters['location_id']
            : ($currentLocation->isAll() ? null : $currentLocation->id());

        $matrixLocations = $activeLocId
            ? $accessibleLocations->where('id', $activeLocId)->values()
            : $accessibleLocations;

        $locIds = $matrixLocations->pluck('id')->all();

        $variants = ProductVariant::query()
            ->with(['product'])
            ->whereHas('product', function ($q) use ($filters, $tree) {
                $q->where('type', '!=', ProductType::Service->value)
                    ->when($filters['category_id'] ?? null, fn ($cq, $catId) => $cq->whereIn('category_id', $tree->descendantIds((int) $catId)));
            })
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = trim($search);
                $q->where(function ($sub) use ($term) {
                    $sub->where('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$term}%"));
                });
            })
            ->when(! empty($filters['low_stock']), function ($q) use ($locIds) {
                $placeholders = implode(',', array_fill(0, max(1, count($locIds)), '?'));
                $q->where('reorder_level', '>', 0)
                    ->whereRaw(
                        "(SELECT COALESCE(SUM(on_hand - reserved), 0) FROM stock_levels WHERE stock_levels.product_variant_id = product_variants.id AND stock_levels.location_id IN ({$placeholders})) <= product_variants.reorder_level",
                        $locIds ?: [0],
                    );
            })
            ->orderBy('sku')
            ->paginate(25)
            ->withQueryString();

        $variantIds = $variants->getCollection()->pluck('id')->all();

        $levels = StockLevel::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('location_id', $locIds)
            ->get()
            ->groupBy('product_variant_id');

        $rows = $variants->through(function (ProductVariant $variant) use ($levels, $matrixLocations, $canViewCost) {
            $varLevels = ($levels->get($variant->id) ?? collect())->keyBy('location_id');
            $byLocation = [];
            $totalOnHand = 0;
            $totalReserved = 0;

            foreach ($matrixLocations as $loc) {
                /** @var StockLevel|null $lvl */
                $lvl = $varLevels->get($loc->id);
                $onHand = (int) ($lvl?->on_hand ?? 0);
                $reserved = (int) ($lvl?->reserved ?? 0);
                $available = max(0, $onHand - $reserved);

                $byLocation[$loc->id] = [
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'available' => $available,
                ];

                $totalOnHand += $onHand;
                $totalReserved += $reserved;
            }

            $totalAvailable = max(0, $totalOnHand - $totalReserved);
            $isLow = $variant->reorder_level > 0 && $totalAvailable <= $variant->reorder_level;

            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'variant_name' => $variant->name,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product?->name,
                'is_serialized' => (bool) $variant->product?->is_serialized,
                'reorder_level' => $variant->reorder_level,
                'reorder_qty' => $variant->reorder_qty,
                'is_low_stock' => $isLow,
                'locations' => $byLocation,
                'totals' => [
                    'on_hand' => $totalOnHand,
                    'reserved' => $totalReserved,
                    'available' => $totalAvailable,
                ],
                ...($canViewCost ? [
                    'average_cost' => $variant->average_cost,
                    'total_value' => Quantity::times(max(0, $totalOnHand), $variant->average_cost),
                ] : []),
            ];
        });

        return Inertia::render('Admin/Stock/Index', [
            'variants' => $rows,
            'matrixLocations' => $matrixLocations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
            ])->values()->all(),
            'allLocations' => $accessibleLocations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
            ])->values()->all(),
            'categories' => $tree->options(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'category_id' => $filters['category_id'] ?? '',
                'location_id' => $activeLocId ?? '',
                'low_stock' => (bool) ($filters['low_stock'] ?? false),
            ],
            'canViewCost' => $canViewCost,
        ]);
    }

    public function show(Request $request, ProductVariant $variant): Response
    {
        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $variant->load(['product']);

        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);
        $locIds = $accessibleLocations->pluck('id')->all();

        $levelsByLoc = StockLevel::query()
            ->where('product_variant_id', $variant->id)
            ->whereIn('location_id', $locIds)
            ->get()
            ->keyBy('location_id');

        $locationCards = $accessibleLocations->map(function (Location $loc) use ($levelsByLoc, $variant, $canViewCost) {
            $lvl = $levelsByLoc->get($loc->id);
            $onHand = (int) ($lvl?->on_hand ?? 0);
            $reserved = (int) ($lvl?->reserved ?? 0);

            return [
                'location_id' => $loc->id,
                'location_name' => $loc->name,
                'location_code' => $loc->code,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => max(0, $onHand - $reserved),
                ...($canViewCost ? [
                    'value' => Quantity::times(max(0, $onHand), $variant->average_cost),
                ] : []),
            ];
        })->values()->all();

        $movements = StockMovement::query()
            ->with(['location:id,name,code', 'user:id,name', 'stockUnit:id,serial_number'])
            ->where('product_variant_id', $variant->id)
            ->whereIn('location_id', $locIds)
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (StockMovement $m) => [
                'id' => $m->id,
                'type' => $m->type->value,
                'type_label' => $m->type->label(),
                'quantity' => $m->quantity,
                'balance_after' => $m->balance_after,
                'location' => $m->location ? ['id' => $m->location->id, 'name' => $m->location->name, 'code' => $m->location->code] : null,
                'user_name' => $m->user?->name,
                'serial_number' => $m->stockUnit?->serial_number,
                'reference_type' => $m->reference_type,
                'reference_id' => $m->reference_id,
                'reference_url' => $this->referenceUrl($m->reference_type, $m->reference_id),
                'business_date' => $m->business_date?->toDateString(),
                'note' => $m->note,
                'created_at' => $m->created_at?->toIso8601String(),
                ...($canViewCost ? [
                    'unit_cost' => $m->unit_cost,
                ] : []),
            ]);

        $reservations = StockReservation::query()
            ->with(['location:id,name,code', 'user:id,name', 'stockUnit:id,serial_number'])
            ->where('product_variant_id', $variant->id)
            ->whereIn('location_id', $locIds)
            ->where('status', ReservationStatus::Active)
            ->latest('id')
            ->get()
            ->map(fn (StockReservation $r) => [
                'id' => $r->id,
                'location_name' => $r->location?->name,
                'location_code' => $r->location?->code,
                'quantity' => $r->quantity,
                'serial_number' => $r->stockUnit?->serial_number,
                'user_name' => $r->user?->name,
                'note' => $r->note,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all();

        $units = $variant->product?->is_serialized
            ? StockUnit::query()
                ->with('location:id,name,code')
                ->where('product_variant_id', $variant->id)
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (StockUnit $u) => [
                    'id' => $u->id,
                    'serial_number' => $u->serial_number,
                    'status' => $u->status->value,
                    'status_label' => $u->status->label(),
                    'condition' => $u->condition->value,
                    'condition_label' => $u->condition->label(),
                    'location_name' => $u->location?->name,
                    'location_code' => $u->location?->code,
                    ...($canViewCost ? ['unit_cost' => $u->unit_cost] : []),
                ])->values()->all()
            : [];

        return Inertia::render('Admin/Stock/Show', [
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'name' => $variant->name,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product?->name,
                'is_serialized' => (bool) $variant->product?->is_serialized,
                'reorder_level' => $variant->reorder_level,
                'reorder_qty' => $variant->reorder_qty,
                'price' => $variant->price,
                ...($canViewCost ? [
                    'average_cost' => $variant->average_cost,
                    'last_cost' => $variant->last_cost,
                ] : []),
            ],
            'locationCards' => $locationCards,
            'movements' => $movements,
            'reservations' => $reservations,
            'units' => $units,
            'canViewCost' => $canViewCost,
        ]);
    }

    /**
     * Quick lookup by barcode, SKU or name for transfer, adjustment and count forms.
     */
    public function lookup(Request $request, CurrentLocation $currentLocation): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $locationId = (int) ($request->query('location_id') ?: ($currentLocation->id() ?? 0));
        $canViewCost = $request->user()->can('view-cost');

        if ($term === '') {
            return response()->json(['variants' => []]);
        }

        // Exact barcode or SKU match first for barcode scanners
        $exact = ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn ($q) => $q->where('type', '!=', ProductType::Service->value))
            ->where(fn ($q) => $q->where('barcode', $term)->orWhere('sku', $term))
            ->first();

        $variants = $exact
            ? collect([$exact])
            : ProductVariant::query()
                ->with('product')
                ->whereHas('product', fn ($q) => $q->where('type', '!=', ProductType::Service->value))
                ->where(function ($q) use ($term) {
                    $q->where('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%")
                        ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$term}%"));
                })
                ->orderBy('sku')
                ->limit(15)
                ->get();

        $levels = $locationId > 0
            ? StockLevel::query()
                ->where('location_id', $locationId)
                ->whereIn('product_variant_id', $variants->pluck('id'))
                ->get()
                ->keyBy('product_variant_id')
            : collect();

        return response()->json([
            'variants' => $variants->map(function (ProductVariant $v) use ($levels, $canViewCost) {
                $lvl = $levels->get($v->id);
                $onHand = (int) ($lvl?->on_hand ?? 0);
                $reserved = (int) ($lvl?->reserved ?? 0);

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'name' => $v->name,
                    'product_name' => $v->product?->name,
                    'is_serialized' => (bool) $v->product?->is_serialized,
                    'on_hand' => $onHand,
                    'available' => max(0, $onHand - $reserved),
                    ...($canViewCost ? ['average_cost' => $v->average_cost] : []),
                ];
            })->values()->all(),
        ]);
    }

    private function referenceUrl(?string $type, ?int $id): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return match ($type) {
            'transfer' => route('transfers.show', $id),
            'stock_count' => route('stock.counts.show', $id),
            'stock_adjustment' => route('stock.adjustments.index', ['highlight' => $id]),
            'opening_stock_import' => route('stock.opening.index', ['import_id' => $id]),
            default => null,
        };
    }
}
