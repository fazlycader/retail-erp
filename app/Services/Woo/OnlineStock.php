<?php

namespace App\Services\Woo;

use App\Models\CompanySetting;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Support\Quantity;

/**
 * What the shop may sell of each variant:
 *
 *   online stock = Σ available (on hand − reserved, each location at least 0) at the online stock locations − the variant's
 *                  online buffer, never below 0, in whole pieces (WooCommerce counts units)
 *
 * Locations not in company_settings.online_stock_locations never feed the shop.
 */
final class OnlineStock
{
    /**
     * @param  array<int, int>  $variantIds
     * @return array<int, int> variant id => whole units
     */
    public static function forVariants(array $variantIds): array
    {
        $locations = CompanySetting::current()->onlineLocationIds();
        $available = $locations === [] || $variantIds === [] ? collect() : StockLevel::query()
            ->whereIn('location_id', $locations)
            ->whereIn('product_variant_id', $variantIds)
            ->selectRaw('product_variant_id, SUM(CASE WHEN on_hand > reserved THEN on_hand - reserved ELSE 0 END) AS available')
            ->groupBy('product_variant_id')
            ->pluck('available', 'product_variant_id');
        $buffers = ProductVariant::withTrashed()->whereIn('id', $variantIds)->pluck('online_buffer', 'id');

        $out = [];
        foreach ($variantIds as $id) {
            $milli = (int) ($available[$id] ?? 0) - (int) ($buffers[$id] ?? 0);
            $out[$id] = max(0, intdiv(max(0, $milli), Quantity::SCALE));
        }

        return $out;
    }

    public static function forVariant(int $variantId): int
    {
        return self::forVariants([$variantId])[$variantId];
    }
}
