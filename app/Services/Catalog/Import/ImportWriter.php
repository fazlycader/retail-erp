<?php

namespace App\Services\Catalog\Import;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\CatalogImport;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CategoryTree;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes one checked row (see ImportRowChecker). Each row is its own transaction: a row that
 * fails at commit time doesn't undo the others, and is reported. Never deletes anything.
 */
class ImportWriter
{
    /** Product columns in the sheet → products columns. */
    private const PRODUCT_COLUMNS = [
        'product_name' => 'name', 'brand' => 'brand', 'type' => 'type', 'description' => 'description', 'category_id' => 'category_id',
        'width_mm' => 'width_mm', 'depth_mm' => 'depth_mm', 'height_mm' => 'height_mm', 'weight_g' => 'weight_g',
        'assembly_required' => 'assembly_required', 'requires_delivery' => 'requires_delivery', 'warranty_months' => 'warranty_months',
        'is_serialized' => 'is_serialized', 'publish_online' => 'publish_online', 'is_active' => 'is_active', 'tags' => 'tags',
    ];

    public function __construct(private CatalogImport $import) {}

    /**
     * @param  array<string, mixed>  $checked  ImportRowChecker::check() result for this row, freshly run
     * @return string created | updated | unchanged
     */
    public function write(array $checked): string
    {
        $data = $checked['data'];

        return DB::transaction(function () use ($checked, $data) {
            if ($checked['action'] === 'unchanged') {
                return 'unchanged';
            }

            $product = $data['product_id']
                ? Product::query()->lockForUpdate()->findOrFail($data['product_id'])
                : $this->newProduct($checked['product_key'], $data);

            $this->updateProduct($product, $data['product']);

            if ($checked['action'] === 'update') {
                $variant = ProductVariant::query()->lockForUpdate()->findOrFail($data['variant_id']);
                $variant->fill($this->variantColumns($data['variant']))->save();

                return 'updated';
            }

            $valueIds = $data['value_ids'];

            foreach ($data['new_values'] as $attributeId => $text) {
                $valueIds[$attributeId] = $this->value((int) $attributeId, $text)->id;
            }

            ksort($valueIds);
            $values = AttributeValue::query()->whereIn('id', $valueIds)->get()->keyBy('attribute_id');
            $order = $product->variantAttributes()->pluck('attributes.id')->all();

            $variant = new ProductVariant([
                'product_id' => $product->id,
                'sku' => $checked['sku'],
                'combination_key' => ProductVariant::combinationKey(array_values($valueIds)),
                'name' => collect($order)->map(fn ($id) => $values->get($id)?->value)->filter()->implode(' / '),
                'price' => 0,
                'min_price' => 0,
                ...$this->variantColumns($data['variant']),
                'sort_order' => (int) $product->variants()->withTrashed()->max('sort_order') + 1,
            ]);
            $variant->save();
            $variant->attributeValues()->sync(array_values($valueIds));

            return 'created';
        });
    }

    /** The first row of a new product creates it (with its options); the next rows find it by slug. */
    private function newProduct(string $slug, array $data): Product
    {
        $existing = Product::query()->where('slug', $slug)->lockForUpdate()->first();

        if ($existing) {
            return $existing;
        }

        $fields = $data['product'];
        $categoryId = $fields['category_id'] ?? $this->categoryFor($fields['category_path']);

        $product = new Product([
            ...$this->productColumns(Arr::except($fields, ['category_id', 'category_path'])),
            'category_id' => $categoryId,
            'type' => $fields['type'] ?? 'stock',
        ]);
        $product->slug = $slug;
        $product->save();

        $axes = Attribute::query()->whereIn('id', $data['axes'])->orderBy('sort_order')->orderBy('name')->pluck('id');
        $product->variantAttributes()->sync($axes->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all());

        return $product;
    }

    private function updateProduct(Product $product, array $fields): void
    {
        if (isset($fields['category_path'])) {
            $fields['category_id'] = $this->categoryFor($fields['category_path']);
        }

        $product->fill($this->productColumns(Arr::except($fields, ['category_path'])));

        if ($product->isDirty()) {
            $product->save();
        }
    }

    /** Find or create each level of "A > B > C" (only reached when the import may create categories). */
    private function categoryFor(string $path): int
    {
        $parentId = null;
        $tree = new CategoryTree;

        foreach (preg_split('/\s*>\s*/', $path) as $i => $name) {
            $found = $tree->findByPath(implode(' > ', array_slice(preg_split('/\s*>\s*/', $path), 0, $i + 1)));

            $parentId = $found?->id ?? Category::query()->create([
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $this->categorySlug($name),
                'sort_order' => (int) Category::query()->where('parent_id', $parentId)->max('sort_order') + 1,
            ])->id;

            $tree = new CategoryTree;
        }

        return $parentId;
    }

    private function categorySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($i = 2; Category::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function value(int $attributeId, string $text): AttributeValue
    {
        $existing = AttributeValue::query()->where('attribute_id', $attributeId)->get()
            ->first(fn (AttributeValue $v) => mb_strtolower($v->value) === mb_strtolower($text));

        return $existing ?? AttributeValue::query()->create([
            'attribute_id' => $attributeId,
            'value' => $text,
            'sort_order' => (int) AttributeValue::query()->where('attribute_id', $attributeId)->max('sort_order') + 1,
        ]);
    }

    private function productColumns(array $fields): array
    {
        return collect($fields)->only(array_keys(self::PRODUCT_COLUMNS))->mapWithKeys(fn ($value, $key) => [self::PRODUCT_COLUMNS[$key] => $value])->all();
    }

    private function variantColumns(array $fields): array
    {
        return collect($fields)->mapWithKeys(fn ($value, $key) => [$key === 'variant_active' ? 'is_active' : $key => $value])->all();
    }
}
