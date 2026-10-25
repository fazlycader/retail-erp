<?php

namespace App\Services\Catalog\Import;

use App\Models\Attribute;
use App\Models\CatalogImport;
use App\Models\CatalogImportRow;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CategoryTree;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Decides what one spreadsheet row would do: create a variant (and maybe its product), update
 * one, change nothing, or fail with reasons. Read-only: the dry run and the commit (which checks
 * every row again against the database as it is then) both use it. Rules that need other rows
 * (duplicate SKUs, rows of one product that disagree) are checked afterwards by ImportService.
 */
class ImportRowChecker
{
    private CategoryTree $tree;

    /** @var Collection<int, Attribute> */
    private Collection $attributes;

    public function __construct(private CatalogImport $import)
    {
        $this->tree = new CategoryTree;
        $this->attributes = Attribute::query()->with('values')->get()->keyBy('id');
    }

    /**
     * @param  array<int|string, mixed>  $raw  cells keyed by column index
     * @return array{action: string, reasons: array<int, string>, data: array<string, mixed>, sku: ?string, barcode: ?string, product_key: ?string, combination_key: ?string}
     */
    public function check(array $raw): array
    {
        $reasons = [];
        $given = [];
        $values = [];
        $headers = $this->import->headers;

        foreach ($this->import->mapping as $column => $target) {
            if (! $target) {
                continue;
            }

            $label = $headers[$column] ?? "column {$column}";

            try {
                $text = ImportFields::text($raw[$column] ?? null);

                if ($text === null) {
                    continue;
                }

                if (str_starts_with($target, 'attribute:')) {
                    $values[(int) Str::after($target, 'attribute:')] = ImportFields::length($text, 80);
                } else {
                    $given[$target] = ImportFields::parse($target, $text);
                }
            } catch (ImportValueError $e) {
                $reasons[] = "{$label}: {$e->getMessage()}.";
            }
        }

        $sku = $given['sku'] ?? null;

        if ($sku === null && ! collect($reasons)->contains(fn ($r) => str_contains($r, 'SKU'))) {
            $reasons[] = 'SKU is blank.';
        }

        if ($reasons !== []) {
            return $this->result(CatalogImportRow::FAIL, $reasons, [], $sku, $given['barcode'] ?? null);
        }

        $productFields = collect($given)->only($this->fieldsAt(ImportFields::PRODUCT))->except(['product_slug'])->all();
        $variantFields = collect($given)->only($this->fieldsAt(ImportFields::VARIANT))->except(['sku'])->all();

        $variant = ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed(), 'product.variantAttributes', 'attributeValues'])->where('sku', $sku)->first();

        return $variant
            ? $this->checkExisting($variant, $given, $productFields, $variantFields, $values)
            : $this->checkNew($given, $productFields, $variantFields, $values);
    }

    private function checkExisting(ProductVariant $variant, array $given, array $productFields, array $variantFields, array $values): array
    {
        $product = $variant->product;
        $reasons = [];

        if ($variant->trashed()) {
            return $this->fail(["SKU {$variant->sku} belongs to a deleted variant. Restore it in the app first (SKUs are never reused)."], $given);
        }

        if ($product->trashed()) {
            return $this->fail(["SKU {$variant->sku} belongs to the deleted product \"{$product->name}\"."], $given);
        }

        if (isset($given['product_slug']) && $given['product_slug'] !== $product->slug) {
            $reasons[] = "SKU {$variant->sku} belongs to product \"{$product->slug}\", but this row says \"{$given['product_slug']}\". Moving variants between products is not done by import.";
        }

        // Options can't be changed by an import: they decide which variant this is.
        $axes = $product->variantAttributes->pluck('id')->all();

        foreach ($values as $attributeId => $text) {
            $name = $this->attributes->get($attributeId)?->name ?? 'Option';
            $current = $variant->attributeValues->firstWhere('attribute_id', $attributeId);

            if (! in_array($attributeId, $axes, true)) {
                $reasons[] = "{$product->name} has no {$name} option.";
            } elseif (mb_strtolower($current?->value ?? '') !== mb_strtolower($text)) {
                $reasons[] = "{$name} is \"{$current?->value}\" for {$variant->sku}; the sheet says \"{$text}\". Changing a variant's options is not done by import.";
            }
        }

        [$productData, $changes, $productReasons] = $this->productChanges($product, $productFields);
        array_push($reasons, ...$productReasons);

        $prices = [
            'price' => $variantFields['price'] ?? $variant->price,
            'min_price' => $variantFields['min_price'] ?? $variant->min_price,
            'online_price' => array_key_exists('online_price', $variantFields) ? $variantFields['online_price'] : $variant->online_price,
        ];
        array_push($reasons, ...$this->priceProblems($prices));

        if (isset($variantFields['barcode']) && $variantFields['barcode'] !== $variant->barcode && $this->barcodeTaken($variantFields['barcode'], $variant->id)) {
            $reasons[] = "Barcode {$variantFields['barcode']} is already used by another variant.";
        }

        foreach ($variantFields as $field => $value) {
            $old = $variant->{$field === 'variant_active' ? 'is_active' : $field};

            if ($old !== $value) {
                $changes[$field] = [$this->show($field, $old), $this->show($field, $value)];
            }
        }

        if ($reasons !== []) {
            return $this->fail($reasons, $given);
        }

        return $this->result(
            $changes === [] ? CatalogImportRow::UNCHANGED : CatalogImportRow::UPDATE,
            [],
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'product' => $productData, 'variant' => $variantFields, 'changes' => $changes],
            $variant->sku,
            $variantFields['barcode'] ?? null,
            $product->slug,
        );
    }

    private function checkNew(array $given, array $productFields, array $variantFields, array $values): array
    {
        $reasons = [];
        $key = $given['product_slug'] ?? (isset($given['product_name']) ? Str::slug($given['product_name']) : null);

        if (! $key) {
            return $this->fail(["New SKU {$given['sku']}: give a product name (or product slug) so we know which product it belongs to."], $given);
        }

        $product = Product::withTrashed()->with(['variantAttributes', 'variants.attributeValues'])->where('slug', $key)->first();

        if ($product?->trashed()) {
            return $this->fail(["Product \"{$key}\" was deleted. Restore it in the app, or use another slug."], $given);
        }

        if (! isset($variantFields['price'])) {
            $reasons[] = "New SKU {$given['sku']} needs a price.";
        }

        $prices = [
            'price' => $variantFields['price'] ?? 0,
            'min_price' => $variantFields['min_price'] ?? 0,
            'online_price' => $variantFields['online_price'] ?? null,
        ];
        array_push($reasons, ...(isset($variantFields['price']) ? $this->priceProblems($prices) : []));

        if (isset($variantFields['barcode']) && $this->barcodeTaken($variantFields['barcode'])) {
            $reasons[] = "Barcode {$variantFields['barcode']} is already used by another variant.";
        }

        // Option values: existing ones (case-insensitive), or new ones only when the import may create them.
        $valueIds = [];
        $newValues = [];

        foreach ($values as $attributeId => $text) {
            $attribute = $this->attributes->get($attributeId);
            $match = $attribute?->values->first(fn ($v) => mb_strtolower($v->value) === mb_strtolower($text));

            if ($match) {
                $valueIds[$attributeId] = $match->id;
            } elseif ($this->import->option('create_missing')) {
                $newValues[$attributeId] = $text;
            } else {
                $reasons[] = "{$attribute?->name} \"{$text}\" doesn't exist. Add it under Attributes, or tick \"create missing categories and option values\".";
            }
        }

        $axes = array_keys($values);
        sort($axes);

        if ($product) {
            $productAxes = $product->variantAttributes->pluck('id')->sort()->values()->all();

            if ($productAxes === []) {
                $reasons[] = "{$product->name} has no options (Colour, Size…), so it can't get another variant from an import. Add its options in the app first.";
            } elseif ($axes !== $productAxes) {
                $names = fn ($ids) => collect($ids)->map(fn ($id) => $this->attributes->get($id)?->name)->implode(', ');
                $reasons[] = "{$product->name}'s variants are made of ".$names($productAxes).'; this row gives '.($axes ? $names($axes) : 'no options').'.';
            } elseif ($newValues === []) {
                $existing = $product->variants()->withTrashed()->where('combination_key', ProductVariant::combinationKey(array_values($valueIds)))->first();

                if ($existing) {
                    $reasons[] = "{$product->name} already has ".($existing->trashed() ? 'a deleted variant' : 'a variant')." with these options ({$existing->name}, SKU {$existing->sku}).";
                }
            }
        }

        [$productData, $changes, $productReasons] = $product ? $this->productChanges($product, $productFields) : $this->newProductData($productFields);
        array_push($reasons, ...$productReasons);

        if ($reasons !== []) {
            return $this->fail($reasons, $given);
        }

        return $this->result(
            CatalogImportRow::CREATE,
            [],
            [
                'product_id' => $product?->id,
                'product' => $productData,
                'variant' => $variantFields,
                'value_ids' => $valueIds,
                'new_values' => $newValues,
                'axes' => $axes,
                'changes' => $changes,
            ],
            $given['sku'],
            $variantFields['barcode'] ?? null,
            $key,
            $this->combination($values),
        );
    }

    /**
     * Given product fields compared with the product: [data to write, changes, reasons].
     *
     * @return array{0: array<string, mixed>, 1: array<string, array{0: mixed, 1: mixed}>, 2: array<int, string>}
     */
    private function productChanges(Product $product, array $fields): array
    {
        [$data, $reasons] = $this->resolveCategory($fields);
        $changes = [];

        foreach ($data as $field => $value) {
            $old = match ($field) {
                'product_name' => $product->name,
                'type' => $product->type->value,
                'category_path' => null,
                default => $product->{$field},
            };

            if ($old != $value) {
                $changes[$field] = [$this->show($field, $old, $product), $this->show($field, $value)];
            }
        }

        return [$data, $changes, $reasons];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<int, string>}
     */
    private function newProductData(array $fields): array
    {
        [$data, $reasons] = $this->resolveCategory($fields);

        return [$data, [], $reasons];
    }

    /**
     * category path → category_id (existing) or category_path (to create, when allowed).
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function resolveCategory(array $fields): array
    {
        if (! isset($fields['category'])) {
            return [$fields, []];
        }

        $path = $fields['category'];
        unset($fields['category']);
        $category = $this->tree->findByPath($path);

        if ($category) {
            return [[...$fields, 'category_id' => $category->id], []];
        }

        $levels = preg_split('/\s*(?:>|›)\s*/u', trim($path));

        if (count($levels) > Category::MAX_DEPTH || in_array('', $levels, true)) {
            return [$fields, ["Category \"{$path}\" is not a valid path (up to ".Category::MAX_DEPTH.' levels, e.g. "Living room > Sofas").']];
        }

        if (! $this->import->option('create_missing')) {
            return [$fields, ["Category \"{$path}\" doesn't exist. Create it under Categories, or tick \"create missing categories and option values\"."]];
        }

        return [[...$fields, 'category_path' => implode(' > ', $levels)], []];
    }

    private function priceProblems(array $prices): array
    {
        $problems = [];

        if ($prices['min_price'] > $prices['price']) {
            $problems[] = 'Minimum price '.Money::fromCents($prices['min_price'])->format().' is above the price '.Money::fromCents($prices['price'])->format().'.';
        }

        if ($prices['online_price'] !== null && $prices['online_price'] < $prices['min_price']) {
            $problems[] = 'Online price '.Money::fromCents($prices['online_price'])->format().' is below the minimum price '.Money::fromCents($prices['min_price'])->format().'.';
        }

        return $problems;
    }

    private function barcodeTaken(string $barcode, ?int $exceptId = null): bool
    {
        return ProductVariant::withTrashed()->where('barcode', $barcode)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }

    /** Option texts as a stable key, so two rows of one product with the same options can be spotted. */
    private function combination(array $values): string
    {
        ksort($values);

        return sha1(collect($values)->map(fn ($text, $id) => $id.'='.mb_strtolower($text))->implode('|'));
    }

    private function show(string $field, mixed $value, ?Product $product = null): mixed
    {
        return match (true) {
            $value === null => null,
            in_array($field, ['price', 'min_price', 'online_price'], true) => Money::fromCents($value)->format(),
            in_array($field, ['reorder_level', 'reorder_qty'], true) => Quantity::format($value),
            is_bool($value) => $value ? 'yes' : 'no',
            $field === 'category_id' => $this->tree->path($value),
            $field === 'description' => Str::limit(strip_tags((string) $value), 60),
            is_array($value) => implode(', ', $value),
            default => $value,
        };
    }

    /**
     * @return array<int, string>
     */
    private function fieldsAt(string $level): array
    {
        return collect(ImportFields::all())->where('level', $level)->keys()->all();
    }

    private function fail(array $reasons, array $given): array
    {
        return $this->result(CatalogImportRow::FAIL, $reasons, [], $given['sku'] ?? null, $given['barcode'] ?? null);
    }

    private function result(string $action, array $reasons, array $data, ?string $sku, ?string $barcode, ?string $productKey = null, ?string $combination = null): array
    {
        return ['action' => $action, 'reasons' => $reasons, 'data' => $data, 'sku' => $sku, 'barcode' => $barcode, 'product_key' => $productKey, 'combination_key' => $combination];
    }
}
