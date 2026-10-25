<?php

namespace App\Http\Controllers\Admin\Workshop;

use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\Catalog\CategoryTree;
use App\Support\CurrentLocation;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaterialController extends Controller
{
    public function index(Request $request, CategoryTree $tree): Response
    {
        $user = $request->user();
        $canViewCosts = $user->can('view-job-costs');
        $search = trim((string) $request->string('search'));
        $lowOnly = $request->boolean('low_stock');

        $workshops = Location::accessibleTo($user)
            ->where('type', LocationType::Workshop->value)
            ->get()
            ->values()
            ->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code]);

        $current = CurrentLocation::get();
        $workshopId = ($current && $current->type === LocationType::Workshop)
            ? $current->id
            : ($request->integer('location_id') ?: ($workshops->first()['id'] ?? CompanySetting::current()->workshop_location_id));

        $materialCategoryIds = $this->materialCategoryIds($tree);

        $variants = $materialCategoryIds === []
            ? collect()
            : ProductVariant::query()
                ->where('is_active', true)
                ->whereHas('product', function ($pq) use ($materialCategoryIds, $search) {
                    $pq->where('is_active', true)
                        ->whereIn('category_id', $materialCategoryIds)
                        ->when($search !== '', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                })
                ->when($search !== '', function ($q) use ($search, $materialCategoryIds) {
                    $q->where(function ($sub) use ($search, $materialCategoryIds) {
                        $sub->where('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', $search)
                            ->orWhereHas('product', fn ($pq) => $pq->whereIn('category_id', $materialCategoryIds)->where('name', 'like', "%{$search}%"));
                    });
                })
                ->with(['product:id,category_id,name,unit'])
                ->orderBy('sku')
                ->get();

        $levels = ($workshopId && $variants->isNotEmpty())
            ? StockLevel::query()
                ->where('location_id', $workshopId)
                ->whereIn('product_variant_id', $variants->pluck('id'))
                ->get()
                ->keyBy('product_variant_id')
            : collect();

        $rows = $variants->map(function (ProductVariant $v) use ($levels, $tree, $canViewCosts) {
            $level = $levels->get($v->id);
            $onHand = (int) ($level?->on_hand ?? 0);
            $reserved = (int) ($level?->reserved ?? 0);
            $available = max(0, $onHand - $reserved);
            $unit = $v->product?->unit ?? 'pcs';
            $isLow = $v->reorder_level > 0 && $available <= $v->reorder_level;

            return [
                'id' => $v->id,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'name' => trim(($v->product?->name ?? $v->sku).($v->name ? ' — '.$v->name : '')),
                'category_path' => $v->product?->category_id ? $tree->path($v->product->category_id) : '',
                'unit' => $unit,
                'on_hand' => $onHand,
                'on_hand_label' => Quantity::format($onHand, $unit),
                'available' => $available,
                'available_label' => Quantity::format($available, $unit),
                'reorder_level' => $v->reorder_level,
                'reorder_level_label' => $v->reorder_level > 0 ? Quantity::format($v->reorder_level, $unit) : null,
                'is_low' => $isLow,
                ...($canViewCosts ? [
                    'average_cost' => $v->average_cost,
                    'stock_value' => Quantity::times($onHand, $v->average_cost),
                ] : []),
            ];
        })->when($lowOnly, fn ($c) => $c->where('is_low', true))->values();

        return Inertia::render('Admin/Workshop/Materials', [
            'materials' => $rows,
            'workshops' => $workshops,
            'selectedLocationId' => $workshopId,
            'filters' => [
                'search' => $search,
                'low_stock' => $lowOnly,
            ],
            'canViewCosts' => $canViewCosts,
        ]);
    }

    public function lookup(Request $request, CategoryTree $tree): JsonResponse
    {
        $q = trim((string) $request->string('q'));
        $locationId = $request->integer('location_id') ?: CurrentLocation::id();

        if ($q === '') {
            return response()->json(['materials' => []]);
        }

        $materialCategoryIds = $this->materialCategoryIds($tree);
        if ($materialCategoryIds === []) {
            return response()->json(['materials' => []]);
        }

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($pq) => $pq->where('is_active', true)->whereIn('category_id', $materialCategoryIds))
            ->with(['product:id,name,unit'])
            ->where(function ($sub) use ($q) {
                $sub->where('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', $q)
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$q}%"));
            })
            ->orderBy('sku')
            ->limit(15)
            ->get();

        $levels = ($locationId && $variants->isNotEmpty())
            ? StockLevel::query()
                ->where('location_id', $locationId)
                ->whereIn('product_variant_id', $variants->pluck('id'))
                ->get()
                ->keyBy('product_variant_id')
            : collect();

        return response()->json([
            'materials' => $variants->map(function (ProductVariant $v) use ($levels) {
                $level = $levels->get($v->id);
                $available = $level ? max(0, $level->on_hand - $level->reserved) : 0;
                $unit = $v->product?->unit ?? 'pcs';

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'name' => trim(($v->product?->name ?? $v->sku).($v->name ? ' — '.$v->name : '')),
                    'unit' => $unit,
                    'available' => $available,
                    'available_label' => Quantity::format($available, $unit),
                ];
            })->values(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function materialCategoryIds(CategoryTree $tree): array
    {
        $roots = Category::query()->where('is_material', true)->pluck('id')->all();
        $ids = [];
        foreach ($roots as $id) {
            array_push($ids, ...$tree->descendantIds((int) $id));
        }

        return array_values(array_unique($ids));
    }
}

