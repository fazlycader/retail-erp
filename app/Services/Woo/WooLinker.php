<?php

namespace App\Services\Woo;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WooLink;
use Illuminate\Support\Str;

/**
 * Initial linking (woo:link): matches the products already in WooCommerce to local variants by SKU,
 * so the first push updates them instead of creating duplicates. Never creates or changes anything in
 * WooCommerce. Reports what couldn't be matched on both sides; ambiguous cases (a SKU twice in
 * WooCommerce, one local product split over several Woo products) are reported, not guessed.
 */
class WooLinker
{
    /** @var array<string, bool> SKUs seen more than once in WooCommerce during this run */
    private array $duplicates = [];

    public function __construct(private WooClient $woo) {}

    /**
     * @return array{linked: array<int, string>, already: int, woo_only: array<int, string>, local_only: array<int, string>, conflicts: array<int, string>}
     */
    public function run(bool $dryRun): array
    {
        $report = ['linked' => [], 'already' => 0, 'woo_only' => [], 'local_only' => [], 'conflicts' => []];
        $this->duplicates = [];
        $local = ProductVariant::query()->with('product:id,name,publish_online')->get()->keyBy(fn ($v) => Str::upper($v->sku));

        // SKU => [woo id, parent id|null]
        $woo = [];
        foreach ($this->woo->each('products', ['status' => 'any']) as $product) {
            if (($product['type'] ?? '') === 'variable') {
                foreach ($this->woo->each("products/{$product['id']}/variations") as $variation) {
                    $this->collect($woo, $report, $variation['sku'] ?? '', (int) $variation['id'], (int) $product['id']);
                }
            } else {
                $this->collect($woo, $report, $product['sku'] ?? '', (int) $product['id'], null);
            }
        }

        $parentsByProduct = [];
        foreach ($woo as $sku => [$wooId, $parentId]) {
            $variant = $local->get($sku);
            if (! $variant) {
                $report['woo_only'][] = $sku;

                continue;
            }
            $parentsByProduct[$variant->product_id][$parentId ?? $wooId][] = [$variant, $wooId, $parentId];
        }

        foreach ($parentsByProduct as $productId => $parents) {
            if (count($parents) > 1) {
                $report['conflicts'][] = 'Product #'.$productId.' ('.Product::withTrashed()->whereKey($productId)->value('name').') is split over WooCommerce products '.implode(', ', array_keys($parents)).': merge them in WooCommerce first.';

                continue;
            }

            $wooProductId = array_key_first($parents);
            if (WooLink::for(WooLink::PRODUCT, $productId)) {
                $report['already']++;

                continue;
            }

            foreach ($parents[$wooProductId] as [$variant, $wooId, $parentId]) {
                $report['linked'][] = $variant->sku;
                if (! $dryRun) {
                    WooLink::remember(WooLink::VARIANT, $variant->id, $wooId, $parentId);
                }
            }
            if (! $dryRun) {
                WooLink::remember(WooLink::PRODUCT, $productId, $wooProductId);
            }
        }

        $linkedSkus = array_flip(array_keys($woo));
        foreach ($local as $sku => $variant) {
            if ($variant->product?->publish_online && ! isset($linkedSkus[$sku]) && ! WooLink::for(WooLink::VARIANT, $variant->id)) {
                $report['local_only'][] = $variant->sku;
            }
        }

        return $report;
    }

    /**
     * @param  array<string, array{0: int, 1: int|null}>  $woo
     * @param  array<string, mixed>  $report
     */
    private function collect(array &$woo, array &$report, string $sku, int $id, ?int $parentId): void
    {
        $sku = Str::upper(trim($sku));
        if ($sku === '') {
            $report['conflicts'][] = "WooCommerce item #{$id} has no SKU: give it the ERP SKU (or delete it) in WooCommerce.";

            return;
        }
        if (isset($woo[$sku]) || isset($this->duplicates[$sku])) {
            $report['conflicts'][] = "SKU {$sku} is used more than once in WooCommerce (#{$id} and others): fix it in WooCommerce.";
            $this->duplicates[$sku] = true;
            unset($woo[$sku]);

            return;
        }
        $woo[$sku] = [$id, $parentId];
    }
}
