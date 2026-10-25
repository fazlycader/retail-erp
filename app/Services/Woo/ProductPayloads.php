<?php

namespace App\Services\Woo;

use App\Enums\ProductType;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\Money;

/**
 * What WooCommerce should show for a product: a simple product when it has one default variant,
 * else a variable product with attributes and one variation per variant. Prices are the online
 * price (blank = shop price) when price sync is on; stock is OnlineStock; made-to-order products
 * are "available on backorder" with the lead time text (meta mvrk_lead_time, shown by the plugin).
 * Dimensions in cm and weight in kg (set WooCommerce's units to cm / kg).
 */
class ProductPayloads
{
    public function __construct(private CategorySync $categories) {}

    public static function isSimple(Product $product): bool
    {
        return $product->variants->count() === 1 && $product->variants->first()->combination_key === '';
    }

    public static function isOnline(Product $product): bool
    {
        return $product->publish_online && $product->is_active && ! $product->trashed() && $product->type !== ProductType::Service;
    }

    /**
     * @param  array<int, int>  $stock  variant id => units (OnlineStock)
     * @return array<string, mixed>
     */
    public function product(Product $product, array $stock): array
    {
        $settings = CompanySetting::current();
        $online = self::isOnline($product);

        if (! $online) {
            return ['status' => 'draft'];
        }

        $payload = [
            'name' => $product->name,
            'slug' => $product->slug,
            'status' => 'publish',
            'type' => self::isSimple($product) ? 'simple' : 'variable',
            'description' => (string) $product->description,
            'categories' => $product->category ? [['id' => $this->categories->wooId($product->category)]] : [],
            'images' => $product->images->whereNull('product_variant_id')->sortBy('sort_order')->values()
                ->map(fn (ProductImage $i) => ['src' => $i->url(), 'alt' => $i->alt ?: $product->name])->all(),
            'dimensions' => [
                'length' => self::cm($product->depth_mm),
                'width' => self::cm($product->width_mm),
                'height' => self::cm($product->height_mm),
            ],
            'weight' => $product->weight_g ? rtrim(rtrim(number_format($product->weight_g / 1000, 3, '.', ''), '0'), '.') : '',
            'meta_data' => [['key' => 'mvrk_lead_time', 'value' => $product->type === ProductType::MadeToOrder ? ($settings->woo_backorder_text ?: 'Made to order') : '']],
        ];

        if (self::isSimple($product)) {
            $payload = [...$payload, ...$this->sellable($product, $product->variants->first(), $stock)];
        } else {
            $payload['attributes'] = $this->attributes($product);
        }

        return $payload;
    }

    /**
     * @param  array<int, int>  $stock
     * @return array<string, mixed>
     */
    public function variation(Product $product, ProductVariant $variant, array $stock): array
    {
        $image = $product->images->firstWhere('product_variant_id', $variant->id);

        return [
            'status' => $variant->is_active && ! $variant->trashed() ? 'publish' : 'private',
            ...$this->sellable($product, $variant, $stock),
            'attributes' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute?->name, 'option' => $v->value])->values()->all(),
            ...($image ? ['image' => ['src' => $image->url()]] : []),
        ];
    }

    /**
     * SKU, price and stock: the parts of a simple product or a variation.
     *
     * @param  array<int, int>  $stock
     * @return array<string, mixed>
     */
    private function sellable(Product $product, ProductVariant $variant, array $stock): array
    {
        $out = ['sku' => $variant->sku];

        if (CompanySetting::current()->woo_sync_prices) {
            $out['regular_price'] = Money::fromCents($variant->online_price ?? $variant->price)->toDecimal();
        }

        if ($product->type === ProductType::MadeToOrder) {
            return [...$out, 'manage_stock' => false, 'stock_status' => 'onbackorder', 'backorders' => 'notify'];
        }

        $units = $stock[$variant->id] ?? 0;

        return [...$out, 'manage_stock' => true, 'stock_quantity' => $units, 'backorders' => 'no', 'stock_status' => $units > 0 ? 'instock' : 'outofstock'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attributes(Product $product): array
    {
        return $product->variants->flatMap(fn (ProductVariant $v) => $v->attributeValues)
            ->groupBy(fn ($v) => $v->attribute?->name)
            ->map(fn ($values, $name) => ['name' => $name, 'visible' => true, 'variation' => true, 'options' => $values->pluck('value')->unique()->values()->all()])
            ->values()->all();
    }

    private static function cm(?int $mm): string
    {
        return $mm ? rtrim(rtrim(number_format($mm / 10, 1, '.', ''), '0'), '.') : '';
    }

    /** Payload fingerprint: unchanged payloads are never sent again. */
    public static function hash(array $payload): string
    {
        $sort = function (&$a) use (&$sort) {
            if (is_array($a)) {
                ksort($a);
                array_walk($a, $sort);
            }
        };
        $sort($payload);

        return hash('sha256', json_encode($payload));
    }
}
