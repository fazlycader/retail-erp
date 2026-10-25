<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Attribute;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PriceChangeLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Services\Catalog\BulkPriceChange;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\VariantGenerator;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ProductController extends Controller
{
    public function index(Request $request, CategoryTree $tree): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(ProductType::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'trashed'])],
            'online' => ['nullable', Rule::in(['yes', 'no'])],
            'low_stock' => ['nullable', 'boolean'],
        ]);

        $actor = $request->user();
        $canViewCost = $actor->can('view-cost');
        $status = $filters['status'] ?? null;

        $products = Product::query()
            ->with([
                'images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            ])
            ->when($status === 'trashed', fn ($q) => $q->onlyTrashed())
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->search($term))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereIn('category_id', $tree->descendantIds((int) $id)))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['online'] ?? null, fn ($q, $online) => $q->where('publish_online', $online === 'yes'))
            ->when(! empty($filters['low_stock']), function ($q) {
                $q->where('type', ProductType::Stock->value)
                    ->whereHas('variants', function ($vq) {
                        $vq->where('reorder_level', '>', 0)
                            ->whereRaw('(SELECT COALESCE(SUM(on_hand - reserved), 0) FROM stock_levels WHERE stock_levels.product_variant_id = product_variants.id) <= product_variants.reorder_level');
                    });
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'brand' => $product->brand,
                'type' => $product->type->value,
                'type_label' => $product->type->label(),
                'category_id' => $product->category_id,
                'category_path' => $tree->path($product->category_id),
                'thumbnail_url' => $product->images->first()?->thumbnailUrl(),
                'is_active' => $product->is_active,
                'publish_online' => $product->publish_online,
                'is_serialized' => $product->is_serialized,
                'deleted_at' => $product->deleted_at?->toIso8601String(),
                'variants_count' => $product->variants->count(),
                'min_variant_price' => (int) $product->variants->min('price'),
                'max_variant_price' => (int) $product->variants->max('price'),
                'first_sku' => $product->variants->first()?->sku,
                'variants' => $product->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'name' => $v->name,
                    'price' => $v->price,
                    'min_price' => $v->min_price,
                    'online_price' => $v->online_price,
                    'is_active' => $v->is_active,
                    ...($canViewCost ? [
                        'average_cost' => $v->average_cost,
                        'last_cost' => $v->last_cost,
                    ] : []),
                ])->values()->all(),
            ]);

        return Inertia::render('Admin/Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => $tree->options(),
            'types' => ProductType::options(),
            'canEdit' => $actor->can('edit-catalog'),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function create(Request $request, CategoryTree $tree): Response
    {
        Gate::authorize('edit-catalog');

        return Inertia::render('Admin/Products/Form', [
            'product' => null,
            ...$this->formLookups($request, $tree),
        ]);
    }

    public function store(ProductRequest $request, ProductService $service): RedirectResponse
    {
        $product = $service->save(new Product, $request->productData(), $request->attributeIds(), $request->variantsData());

        return to_route('products.edit', $product)->with('success', "{$product->name} was added.");
    }

    public function edit(Request $request, int $product, CategoryTree $tree): Response
    {
        $record = Product::withTrashed()
            ->with([
                'category.parent.parent',
                'variantAttributes',
                'images',
                'variants' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')->with(['attributeValues', 'suppliers']),
            ])
            ->findOrFail($product);

        $canViewCost = $request->user()->can('view-cost');

        $priceLogs = PriceChangeLog::query()
            ->with(['user:id,name', 'variant' => fn ($q) => $q->withTrashed()->select(['id', 'sku', 'name'])])
            ->whereIn('product_variant_id', $record->variants()->withTrashed()->pluck('id'))
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PriceChangeLog $log) => [
                'id' => $log->id,
                'sku' => $log->variant?->sku,
                'variant_name' => $log->variant?->name,
                'user' => $log->user?->name ?? 'System',
                'field' => $log->field,
                'old_value' => $log->old_value,
                'new_value' => $log->new_value,
                'source' => $log->source,
                'note' => $log->note,
                'created_at' => $log->created_at?->format('j M Y, g:i a'),
            ]);

        return Inertia::render('Admin/Products/Form', [
            'product' => [
                'id' => $record->id,
                'category_id' => $record->category_id,
                'name' => $record->name,
                'slug' => $record->slug,
                'brand' => $record->brand,
                'description' => $record->description,
                'type' => $record->type->value,
                'unit' => $record->unit ?? 'pcs',
                'width_mm' => $record->width_mm,
                'depth_mm' => $record->depth_mm,
                'height_mm' => $record->height_mm,
                'weight_g' => $record->weight_g,
                'assembly_required' => $record->assembly_required,
                'requires_delivery' => $record->requires_delivery,
                'warranty_months' => $record->warranty_months,
                'effective_warranty_months' => $record->effectiveWarrantyMonths(),
                'is_serialized' => $record->is_serialized,
                'publish_online' => $record->publish_online,
                'seo_title' => $record->seo_title,
                'seo_description' => $record->seo_description,
                'is_active' => $record->is_active,
                'tags' => $record->tags ?? [],
                'deleted_at' => $record->deleted_at?->toIso8601String(),
                'attribute_ids' => $record->variantAttributes->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'images' => $record->images->map->toArrayForPage()->values()->all(),
                'variants' => $record->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'name' => $v->name,
                    'combination_key' => $v->combination_key,
                    'value_ids' => $v->attributeValues->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'price' => $v->price,
                    'price_input' => Money::fromCents($v->price)->toDecimal(),
                    'min_price' => $v->min_price,
                    'min_price_input' => Money::fromCents($v->min_price)->toDecimal(),
                    'online_price' => $v->online_price,
                    'online_price_input' => $v->online_price !== null ? Money::fromCents($v->online_price)->toDecimal() : '',
                    'reorder_level' => $v->reorder_level,
                    'reorder_level_input' => $v->reorder_level ? Quantity::toDecimal($v->reorder_level) : '0',
                    'reorder_qty' => $v->reorder_qty,
                    'reorder_qty_input' => $v->reorder_qty ? Quantity::toDecimal($v->reorder_qty) : '0',
                    'is_active' => $v->is_active,
                    'suppliers' => $v->suppliers->map(fn (Supplier $s) => [
                        'supplier_id' => $s->id,
                        'supplier_name' => $s->name,
                        'supplier_sku' => $s->pivot->supplier_sku,
                        'is_preferred' => (bool) $s->pivot->is_preferred,
                        ...($canViewCost ? [
                            'last_cost' => $s->pivot->last_cost !== null ? (int) $s->pivot->last_cost : null,
                            'last_cost_input' => $s->pivot->last_cost !== null ? Money::fromCents((int) $s->pivot->last_cost)->toDecimal() : '',
                        ] : []),
                    ])->values()->all(),
                    ...($canViewCost ? [
                        'average_cost' => $v->average_cost,
                        'last_cost' => $v->last_cost,
                    ] : []),
                ])->values()->all(),
            ],
            'priceLogs' => $priceLogs,
            ...$this->formLookups($request, $tree),
        ]);
    }

    public function update(ProductRequest $request, Product $product, ProductService $service): RedirectResponse
    {
        $service->save($product, $request->productData(), $request->attributeIds(), $request->variantsData());

        return back()->with('success', "{$product->name} was saved.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $product->delete();
        AuditLog::record('product.deleted', $product, "Deleted {$product->name}");

        return to_route('products.index')->with('success', "{$product->name} was moved to trash.");
    }

    public function restore(int $product): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $record = Product::onlyTrashed()->findOrFail($product);
        $record->restore();
        AuditLog::record('product.restored', $record, "Restored {$record->name}");

        return back()->with('success', "{$record->name} was restored.");
    }

    public function generateVariants(Request $request, VariantGenerator $generator): JsonResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'groups' => ['present', 'array'],
            'base_sku' => ['nullable', 'string', 'max:40'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $existing = isset($data['product_id'])
            ? ProductVariant::withTrashed()->where('product_id', (int) $data['product_id'])->get()
            : null;

        try {
            $rows = $generator->generate($data['groups'], (string) ($data['base_sku'] ?? 'SKU'), $existing);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['variants' => $rows]);
    }

    public function bulkPricePreview(Request $request, BulkPriceChange $bulkPrice): JsonResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'percent' => ['required', 'string', 'max:10'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(BulkPriceChange::FIELDS)],
            'round_to' => ['nullable', 'integer', Rule::in(BulkPriceChange::ROUNDING)],
        ]);

        try {
            $preview = $bulkPrice->preview(
                array_map('intval', $data['product_ids']),
                $data['percent'],
                $data['fields'],
                (int) ($data['round_to'] ?? 1),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    public function bulk(Request $request, BulkPriceChange $bulkPrice): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'publish_online', 'unpublish_online', 'change_category', 'price_change'])],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'category_id' => ['required_if:action,change_category', 'nullable', 'integer', 'exists:categories,id'],
            'percent' => ['required_if:action,price_change', 'nullable', 'string', 'max:10'],
            'fields' => ['required_if:action,price_change', 'nullable', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(BulkPriceChange::FIELDS)],
            'round_to' => ['nullable', 'integer', Rule::in(BulkPriceChange::ROUNDING)],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $count = count($ids);

        if ($data['action'] === 'price_change') {
            try {
                $changed = $bulkPrice->apply($ids, (string) $data['percent'], $data['fields'], (int) ($data['round_to'] ?? 1));
            } catch (InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }

            return back()->with('success', "Updated prices on {$changed} variant(s) across {$count} product(s).");
        }

        DB::transaction(function () use ($data, $ids, $count) {
            match ($data['action']) {
                'activate' => Product::query()->whereKey($ids)->update(['is_active' => true]),
                'deactivate' => Product::query()->whereKey($ids)->update(['is_active' => false]),
                'publish_online' => Product::query()->whereKey($ids)->update(['publish_online' => true]),
                'unpublish_online' => Product::query()->whereKey($ids)->update(['publish_online' => false]),
                'change_category' => Product::query()->whereKey($ids)->update(['category_id' => (int) $data['category_id']]),
            };

            AuditLog::record("catalog.bulk_{$data['action']}", null, "Bulk {$data['action']} on {$count} products", null, ['product_ids' => $ids, 'category_id' => $data['category_id'] ?? null]);
        });

        return back()->with('success', "Updated {$count} product(s).");
    }

    /**
     * @return array<string, mixed>
     */
    private function formLookups(Request $request, CategoryTree $tree): array
    {
        $categoryDefaults = Category::query()->get(['id', 'parent_id', 'default_warranty_months'])->keyBy('id');

        return [
            'categories' => array_map(function (array $opt) use ($categoryDefaults) {
                $cat = $categoryDefaults->get($opt['id']);
                $warranty = null;
                for ($d = 0; $cat && $d < Category::MAX_DEPTH; $d++) {
                    if ($cat->default_warranty_months !== null) {
                        $warranty = $cat->default_warranty_months;
                        break;
                    }
                    $cat = $categoryDefaults->get($cat->parent_id);
                }

                return [...$opt, 'effective_warranty_months' => $warranty];
            }, $tree->options()),
            'types' => ProductType::options(),
            'attributes' => Attribute::query()->with('values')->orderBy('sort_order')->orderBy('name')->get()->map(fn (Attribute $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'values' => $a->values->map->toOption()->values()->all(),
            ])->values()->all(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])->values()->all(),
            'tagSuggestions' => ['living room', 'bedroom', 'dining', 'office', 'outdoor', 'solid wood', 'teak', 'recliner', 'clearance'],
            'canEdit' => $request->user()->can('edit-catalog'),
            'canViewCost' => $request->user()->can('view-cost'),
        ];
    }
}
