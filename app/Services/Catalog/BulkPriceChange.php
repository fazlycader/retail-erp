<?php

namespace App\Services\Catalog;

use App\Models\AuditLog;
use App\Models\PriceChangeLog;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "+5% on these products": preview first, then apply. Each chosen field (price, min_price,
 * online_price) of every active variant of the chosen products changes by the same percentage,
 * rounded half up, then to the nearest $roundTo cents (e.g. Rs. 10 = 1000). If any variant would
 * end up with min_price > price or online_price < min_price, nothing is applied: the preview
 * lists them. Logged per variant in price_change_logs (source "bulk") plus one audit row.
 */
class BulkPriceChange
{
    public const FIELDS = ['price', 'min_price', 'online_price'];

    public const ROUNDING = [1, 100, 1000, 10000]; // cents: none, Rs. 1, Rs. 10, Rs. 100

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, string>  $fields
     * @return array{rows: array<int, array<string, mixed>>, blocked: array<int, array<string, mixed>>, count: int}
     */
    public function preview(array $productIds, string $percent, array $fields, int $roundTo = 1): array
    {
        $basisPoints = self::basisPoints($percent);
        $rows = [];
        $blocked = [];

        foreach ($this->variants($productIds) as $variant) {
            $next = $this->changed($variant, $basisPoints, $fields, $roundTo);
            $row = [
                'variant_id' => $variant->id,
                'product' => $variant->product->name,
                'variant' => $variant->name,
                'sku' => $variant->sku,
                'changes' => collect($fields)->filter(fn ($f) => $variant->{$f} !== null)->mapWithKeys(fn ($f) => [$f => ['old' => $variant->{$f}, 'new' => $next[$f]]])->all(),
            ];
            $rows[] = $row;

            if ($problem = $this->problem($next)) {
                $blocked[] = [...$row, 'problem' => $problem];
            }
        }

        return ['rows' => $rows, 'blocked' => $blocked, 'count' => count($rows)];
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<int, string>  $fields
     * @return int variants changed
     */
    public function apply(array $productIds, string $percent, array $fields, int $roundTo = 1): int
    {
        $preview = $this->preview($productIds, $percent, $fields, $roundTo);

        if ($preview['blocked'] !== []) {
            throw new InvalidArgumentException(count($preview['blocked']).' variant(s) would break the minimum price rule. Include the minimum price in the change, or fix them first.');
        }

        return DB::transaction(fn () => PriceChangeLog::from('bulk', function () use ($productIds, $percent, $fields, $roundTo) {
            $basisPoints = self::basisPoints($percent);
            $count = 0;

            foreach ($this->variants($productIds, lock: true) as $variant) {
                $variant->fill($this->changed($variant, $basisPoints, $fields, $roundTo));

                if ($variant->isDirty()) {
                    $variant->save();
                    $count++;
                }
            }

            AuditLog::record('catalog.bulk_price', null, "Changed prices by {$percent}% (".implode(', ', $fields).") on {$count} variants of ".count($productIds).' products', null, ['percent' => $percent, 'fields' => $fields, 'round_to' => $roundTo, 'product_ids' => $productIds]);

            return $count;
        }, "{$percent}%"));
    }

    /** "5" → 500, "-12.5" → -1250. Between -90% and +500%. */
    public static function basisPoints(string $percent): int
    {
        $percent = trim($percent);

        if (! preg_match('/^([+-])?(\d{1,3})(?:\.(\d{1,2}))?$/', $percent, $m)) {
            throw new InvalidArgumentException('Enter a percentage like 5, -10 or 2.5.');
        }

        $bp = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        $bp = ($m[1] ?? '') === '-' ? -$bp : $bp;

        if ($bp === 0 || $bp < -9000 || $bp > 50000) {
            throw new InvalidArgumentException('The change must be between -90% and +500%, and not 0.');
        }

        return $bp;
    }

    /** Half up, then to the nearest multiple of $roundTo. */
    public static function adjust(int $cents, int $basisPoints, int $roundTo = 1): int
    {
        $raw = intdiv($cents * (10000 + $basisPoints) * 2 + 10000, 20000);

        return $roundTo > 1 ? intdiv($raw * 2 + $roundTo, $roundTo * 2) * $roundTo : $raw;
    }

    /**
     * @return array<string, ?int>
     */
    private function changed(ProductVariant $variant, int $basisPoints, array $fields, int $roundTo): array
    {
        $next = $variant->only(self::FIELDS);

        foreach ($fields as $field) {
            if ($variant->{$field} !== null) {
                $next[$field] = self::adjust($variant->{$field}, $basisPoints, $roundTo);
            }
        }

        return $next;
    }

    private function problem(array $prices): ?string
    {
        if ($prices['min_price'] > $prices['price']) {
            return 'Price '.Money::fromCents($prices['price'])->format().' would be below the minimum '.Money::fromCents($prices['min_price'])->format();
        }

        if ($prices['online_price'] !== null && $prices['online_price'] < $prices['min_price']) {
            return 'Online price '.Money::fromCents($prices['online_price'])->format().' would be below the minimum '.Money::fromCents($prices['min_price'])->format();
        }

        return null;
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    private function variants(array $productIds, bool $lock = false): Collection
    {
        return ProductVariant::query()
            ->with('product:id,name')
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderBy('product_id')->orderBy('sort_order')
            ->get();
    }
}
