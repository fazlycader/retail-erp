<?php

namespace App\Services\Catalog;

use App\Models\AttributeValue;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * "Pick attributes and values → every combination". 2 colours × 3 sizes = 6 rows, ordered by
 * the attribute order given, with a suggested SKU (BASE-GRY-3SE) and name ("Grey / 3 seater").
 * Rows keep any existing variant with the same combination, so regenerating never loses
 * edited SKUs or prices. No attributes = one default variant.
 */
class VariantGenerator
{
    public const MAX_COMBINATIONS = 200;

    /**
     * @param  array<int, array<int, int>>  $groups  [attribute_id => [value ids]], in attribute order
     * @param  Collection<int, ProductVariant>|null  $existing  the product's current variants (with attributeValues)
     * @return array<int, array{combination_key: string, value_ids: array<int, int>, name: string, sku: string, existing_id: ?int}>
     */
    public function generate(array $groups, string $baseSku, ?Collection $existing = null): array
    {
        $groups = array_filter($groups, fn ($ids) => $ids !== []);
        $values = AttributeValue::query()->whereIn('id', array_merge([], ...array_values($groups)))->get()->keyBy('id');

        // Only values that belong to the attribute they were picked under.
        foreach ($groups as $attributeId => $ids) {
            $groups[$attributeId] = array_values(array_filter(array_unique(array_map('intval', $ids)), fn ($id) => $values->get($id)?->attribute_id === (int) $attributeId));
        }

        $combinations = [[]];

        foreach (array_filter($groups) as $ids) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($ids as $id) {
                    $next[] = [...$combination, $id];
                }
            }

            $combinations = $next;

            if (count($combinations) > self::MAX_COMBINATIONS) {
                throw new \InvalidArgumentException('That makes more than '.self::MAX_COMBINATIONS.' variants. Pick fewer values.');
            }
        }

        $byKey = ($existing ?? collect())->keyBy('combination_key');
        $base = strtoupper(trim($baseSku)) ?: 'SKU';
        $seen = [];

        return array_map(function (array $ids) use ($values, $byKey, $base, &$seen) {
            $key = ProductVariant::combinationKey($ids);
            $current = $byKey->get($key);
            $sku = $current?->sku ?? self::uniqueInList($base.implode('', array_map(fn ($id) => '-'.$values[$id]->code, $ids)), $seen);
            $seen[] = $sku;

            return [
                'combination_key' => $key,
                'value_ids' => $ids,
                'name' => implode(' / ', array_map(fn ($id) => $values[$id]->value, $ids)),
                'sku' => $sku,
                'existing_id' => $current?->id,
            ];
        }, $combinations);
    }

    /** Two values with the same code (Grey / Green → GRE) would suggest the same SKU: number the repeats. */
    private static function uniqueInList(string $sku, array $seen): string
    {
        $candidate = $sku;

        for ($i = 2; in_array($candidate, $seen, true); $i++) {
            $candidate = "{$sku}-{$i}";
        }

        return $candidate;
    }
}
