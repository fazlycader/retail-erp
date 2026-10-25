<?php

namespace App\Services\Catalog;

use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\RichText;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a product with its attribute axes and variants in one transaction. The rules every
 * caller (form, import) relies on live here:
 * - no attributes = exactly one default variant; otherwise every variant has exactly one value
 *   of each attribute, and no two variants share a combination;
 * - SKUs and barcodes are unique across all variants, deleted ones included;
 * - min_price ≤ price, and online_price (when set) ≥ min_price.
 * Variants missing from the list are soft deleted (a deleted combination added again is restored).
 */
class ProductService
{
    /**
     * @param  array<string, mixed>  $data  product columns
     * @param  array<int, int>  $attributeIds  in order
     * @param  array<int, array{id?: ?int, value_ids: array<int, int>, sku: string, barcode?: ?string, price: int, min_price: int, online_price: ?int, reorder_level: int, reorder_qty: int, is_active: bool}>  $variants
     */
    public function save(Product $product, array $data, array $attributeIds, array $variants): Product
    {
        $attributeIds = array_values(array_unique(array_map('intval', $attributeIds)));
        $rows = $this->checkVariants($product, $attributeIds, $variants);

        return DB::transaction(function () use ($product, $data, $attributeIds, $rows) {
            $creating = ! $product->exists;
            $before = $creating ? null : $product->only(['name', 'category_id', 'type', 'is_active', 'publish_online']);

            if (array_key_exists('description', $data)) {
                $data['description'] = RichText::sanitize($data['description']);
            }

            $product->fill($data);
            $product->slug = filled($data['slug'] ?? null) ? $data['slug'] : ($product->slug ?: Product::uniqueSlug($product->name, $product->id));
            $product->save();

            $product->variantAttributes()->sync(collect($attributeIds)->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all());

            $kept = [];

            foreach ($rows as $i => $row) {
                $variant = $row['id']
                    ? $product->variants()->withTrashed()->findOrFail($row['id'])
                    : ($product->variants()->withTrashed()->where('combination_key', $row['combination_key'])->first() ?? new ProductVariant(['product_id' => $product->id]));

                if ($variant->trashed()) {
                    $variant->restore();
                }

                $variant->fill([
                    ...Arr::only($row, ['sku', 'price', 'min_price', 'online_price', 'reorder_level', 'reorder_qty', 'is_active', 'combination_key', 'name']),
                    'sort_order' => $i,
                ]);

                // Blank barcode on a new variant = generated; blank on an existing one = keep it.
                if (filled($row['barcode'] ?? null)) {
                    $variant->barcode = $row['barcode'];
                }

                $variant->save();
                $variant->attributeValues()->sync($row['value_ids']);

                if ($row['suppliers'] !== null) {
                    $variant->suppliers()->sync(collect($row['suppliers'])->mapWithKeys(fn (array $s) => [
                        (int) $s['supplier_id'] => [
                            'supplier_sku' => filled($s['supplier_sku'] ?? null) ? trim((string) $s['supplier_sku']) : null,
                            'last_cost' => isset($s['last_cost']) && $s['last_cost'] !== null ? (int) $s['last_cost'] : null,
                            'is_preferred' => (bool) ($s['is_preferred'] ?? false),
                        ],
                    ])->all());
                }

                $kept[] = $variant->id;
            }

            $product->variants()->whereKeyNot($kept)->get()->each->delete();

            $product->unsetRelation('variants');

            AuditLog::record(
                $creating ? 'product.created' : 'product.updated',
                $product,
                ($creating ? 'Added ' : 'Updated ').$product->name.' ('.count($rows).' '.str('variant')->plural(count($rows)).')',
                $before,
                $product->only(['name', 'category_id', 'type', 'is_active', 'publish_online']),
            );

            return $product;
        });
    }

    /**
     * Checks the variant list and returns it with combination keys and names. Throws a
     * ValidationException keyed like the form ("variants.2.sku").
     *
     * @return array<int, array<string, mixed>>
     */
    public function checkVariants(Product $product, array $attributeIds, array $variants): array
    {
        $errors = [];

        if ($variants === []) {
            throw ValidationException::withMessages(['variants' => 'A product needs at least one variant.']);
        }

        if ($attributeIds === [] && count($variants) > 1) {
            throw ValidationException::withMessages(['variants' => 'Without options a product has exactly one variant. Add attributes (Colour, Size…) to have more.']);
        }

        $allValueIds = array_merge([], ...array_map(fn ($v) => array_map('intval', $v['value_ids'] ?? []), $variants));
        $values = AttributeValue::query()->whereIn('id', $allValueIds)->get()->keyBy('id');
        $ownIds = $product->exists ? $product->variants()->withTrashed()->pluck('id')->all() : [];
        $rows = [];
        $seen = ['combination' => [], 'sku' => [], 'barcode' => []];

        foreach (array_values($variants) as $i => $variant) {
            $valueIds = array_values(array_unique(array_map('intval', $variant['value_ids'] ?? [])));
            $byAttribute = collect($valueIds)->map(fn ($id) => $values->get($id))->filter()->groupBy('attribute_id');
            $id = isset($variant['id']) ? (int) $variant['id'] : null;

            if ($id && ! in_array($id, $ownIds, true)) {
                $errors["variants.{$i}.id"] = 'This variant belongs to another product.';
            }

            if (count($valueIds) !== count($attributeIds) || $byAttribute->keys()->map(fn ($k) => (int) $k)->sort()->values()->all() !== collect($attributeIds)->sort()->values()->all()
                || $byAttribute->contains(fn ($group) => $group->count() > 1)) {
                $errors["variants.{$i}.value_ids"] = 'Pick exactly one value for each option ('.count($attributeIds).').';
            }

            $key = ProductVariant::combinationKey($valueIds);

            if (isset($seen['combination'][$key])) {
                $errors["variants.{$i}.value_ids"] = 'Same options as row '.($seen['combination'][$key] + 1).'.';
            }

            $seen['combination'][$key] = $i;

            $sku = strtoupper(trim((string) ($variant['sku'] ?? '')));
            $barcode = trim((string) ($variant['barcode'] ?? ''));

            foreach (['sku' => $sku, 'barcode' => $barcode] as $field => $value) {
                if ($value === '') {
                    continue;
                }

                if (isset($seen[$field][$value])) {
                    $errors["variants.{$i}.{$field}"] = strtoupper($field === 'sku' ? 'SKU' : 'Barcode').' already used on row '.($seen[$field][$value] + 1).'.';
                }

                $seen[$field][$value] = $i;
            }

            if ($sku === '') {
                $errors["variants.{$i}.sku"] = 'Enter a SKU.';
            }

            if ((int) $variant['min_price'] > (int) $variant['price']) {
                $errors["variants.{$i}.min_price"] = 'The minimum price can’t be above the price.';
            }

            if ($variant['online_price'] !== null && (int) $variant['online_price'] < (int) $variant['min_price']) {
                $errors["variants.{$i}.online_price"] = 'The online price can’t be below the minimum price.';
            }

            $rows[] = [
                'id' => $id,
                'value_ids' => $valueIds,
                'combination_key' => $key,
                'name' => collect($attributeIds)->map(fn ($attributeId) => $byAttribute->get($attributeId)?->first()?->value)->filter()->implode(' / '),
                'sku' => $sku,
                'barcode' => $barcode ?: null,
                'price' => (int) $variant['price'],
                'min_price' => (int) $variant['min_price'],
                'online_price' => $variant['online_price'] === null ? null : (int) $variant['online_price'],
                'reorder_level' => (int) ($variant['reorder_level'] ?? 0),
                'reorder_qty' => (int) ($variant['reorder_qty'] ?? 0),
                'is_active' => (bool) ($variant['is_active'] ?? true),
                'suppliers' => array_key_exists('suppliers', $variant) ? (array) $variant['suppliers'] : null,
            ];
        }

        // Taken by another product's variant (deleted ones too: a SKU is never reused).
        // A deleted variant of this product that gets restored for the same combination keeps its own SKU.
        $restoring = $product->exists
            ? $product->variants()->onlyTrashed()->whereIn('combination_key', array_column($rows, 'combination_key'))->pluck('id')->all()
            : [];
        $mine = array_merge(array_filter(array_column($rows, 'id')), $restoring);

        foreach (['sku', 'barcode'] as $field) {
            $taken = ProductVariant::withTrashed()
                ->whereIn($field, array_filter(array_column($rows, $field)))
                ->whereKeyNot($mine)
                ->pluck($field)->all();

            foreach ($rows as $i => $row) {
                if ($row[$field] !== null && in_array($row[$field], $taken, true)) {
                    $errors["variants.{$i}.{$field}"] = ($field === 'sku' ? 'SKU' : 'Barcode')." {$row[$field]} is already used by another product.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }
}
