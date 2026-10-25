<?php

namespace App\Services\Woo;

use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\WooLink;
use App\Models\WooSyncItem;
use App\Models\WooSyncLog;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sends the dirty queue to WooCommerce (run every minute by the scheduler as woo:push):
 *
 * - products: one POST /products/batch for up to 100 products (create + update), then per variable
 *   product POST /products/{id}/variations/batch (≤ 100 variations each). Payloads whose hash equals
 *   the last one pushed are left out; a product that is neither online nor linked is just dropped.
 *   A new product is first looked up by SKU in WooCommerce, so a push never creates a duplicate.
 * - order statuses: PUT /orders/{id} completed + an order note.
 *
 * Stops starting new work after $budget seconds (cron runs every minute on shared hosting). A failed
 * item is retried later with backoff (1, 5, 15, 60 minutes).
 */
class WooPusher
{
    private const BACKOFF_MINUTES = [1, 5, 15, 60];

    public function __construct(private WooClient $woo, private ProductPayloads $payloads) {}

    /**
     * @return array{products: int, variations: int, skipped: int, orders: int, failed: int}
     */
    public function run(int $budget = 45, ?int $limit = null): array
    {
        $stats = ['products' => 0, 'variations' => 0, 'skipped' => 0, 'orders' => 0, 'failed' => 0];

        if (! $this->woo->configured()) {
            return $stats;
        }

        $started = microtime(true);
        $inTime = fn () => microtime(true) - $started < $budget;

        while ($inTime()) {
            $items = WooSyncItem::query()->due()->where('kind', WooSyncItem::PRODUCT)->limit(min($limit ?? WooClient::BATCH_LIMIT, WooClient::BATCH_LIMIT))->get();
            if ($items->isEmpty()) {
                break;
            }
            $this->pushProducts($items, $stats, $inTime);
            if ($limit !== null) {
                break;
            }
        }

        foreach (WooSyncItem::query()->due()->where('kind', WooSyncItem::ORDER_STATUS)->limit(50)->get() as $item) {
            if (! $inTime()) {
                break;
            }
            $this->attempt($item, function () use ($item, &$stats) {
                $this->pushOrderStatus($item->subject_id);
                $stats['orders']++;
            }, $stats);
        }

        CompanySetting::current()->forceFill(['woo_last_push_at' => now()])->save();

        return $stats;
    }

    /**
     * @param  Collection<int, WooSyncItem>  $items
     * @param  array<string, int>  $stats
     */
    private function pushProducts($items, array &$stats, callable $inTime): void
    {
        $products = Product::withTrashed()->with(['category', 'images', 'variants' => fn ($q) => $q->withTrashed()->orderBy('sort_order')->orderBy('id'), 'variants.attributeValues.attribute'])
            ->whereIn('id', $items->pluck('subject_id'))->get()->keyBy('id');
        $links = WooLink::query()->where('local_type', WooLink::PRODUCT)->whereIn('local_id', $products->keys())->get()->keyBy('local_id');
        $stock = OnlineStock::forVariants($products->flatMap(fn ($p) => $p->variants->pluck('id'))->all());

        $create = [];
        $update = [];
        $done = [];

        foreach ($items as $item) {
            $product = $products->get($item->subject_id);
            $link = $links->get($item->subject_id);

            // Gone, or not online and never sent: nothing for WooCommerce to know.
            if (! $product || (! $link && ! ProductPayloads::isOnline($product))) {
                $item->delete();

                continue;
            }

            try {
                $payload = $this->payloads->product($product, $stock);
            } catch (Throwable $e) {
                $this->fail($item, $e, $stats);

                continue;
            }

            if (! $link && ($existing = $this->existingBySku($product))) {
                $link = WooLink::remember(WooLink::PRODUCT, $product->id, $existing);
            }

            $hash = ProductPayloads::hash($payload);
            if ($link && $link->last_pushed_hash === $hash) {
                $stats['skipped']++;
                $done[$product->id] = [$item, $link->woo_id];

                continue;
            }

            if ($link) {
                $update[] = ['id' => $link->woo_id, ...$payload];
            } else {
                $create[] = $payload;
            }
            $done[$product->id] = [$item, $link?->woo_id, $hash, $link ? 'update' : 'create', $link ? count($update) - 1 : count($create) - 1];
        }

        if ($create !== [] || $update !== []) {
            try {
                $result = $this->woo->post('products/batch', array_filter(['create' => $create, 'update' => $update]));
            } catch (Throwable $e) {
                foreach ($done as [$item]) {
                    $this->fail($item, $e, $stats);
                }

                return;
            }

            foreach ($done as $productId => $entry) {
                if (count($entry) < 3) {
                    continue; // unchanged
                }
                [$item, $wooId, $hash, $op, $index] = $entry;
                $row = $result[$op][$index] ?? null;

                if (! $row || isset($row['error'])) {
                    $this->fail($item, new WooApiException($row['error']['message'] ?? 'No answer for this product in the batch.'), $stats);
                    unset($done[$productId]);

                    continue;
                }

                WooLink::remember(WooLink::PRODUCT, $productId, (int) $row['id'], null, $hash);
                $done[$productId] = [$item, (int) $row['id']];
                $stats['products']++;
            }
        }

        foreach ($done as $productId => [$item, $wooId]) {
            $product = $products->get($productId);

            if (! $inTime()) {
                return; // the item stays queued for the next run
            }

            $this->attempt($item, function () use ($product, $wooId, $stock, &$stats) {
                if (ProductPayloads::isSimple($product)) {
                    $variant = $product->variants->first();
                    WooLink::remember(WooLink::VARIANT, $variant->id, $wooId);
                } elseif (ProductPayloads::isOnline($product)) {
                    $stats['variations'] += $this->pushVariations($product, $wooId, $stock, $stats);
                }
            }, $stats);
        }
    }

    /**
     * @param  array<int, int>  $stock
     * @param  array<string, int>  $stats
     */
    private function pushVariations(Product $product, int $wooProductId, array $stock, array &$stats): int
    {
        $links = WooLink::query()->where('local_type', WooLink::VARIANT)->whereIn('local_id', $product->variants->pluck('id'))->get()->keyBy('local_id');
        $ops = [];

        foreach ($product->variants as $variant) {
            $link = $links->get($variant->id);
            if (! $link && ($variant->trashed() || ! $variant->is_active)) {
                continue;
            }

            $payload = $this->payloads->variation($product, $variant, $stock);
            $hash = ProductPayloads::hash($payload);

            if ($link && $link->last_pushed_hash === $hash) {
                $stats['skipped']++;

                continue;
            }

            $ops[] = [$variant, $hash, $link ? ['update', ['id' => $link->woo_id, ...$payload]] : ['create', $payload]];
        }

        $sent = 0;
        foreach (array_chunk($ops, WooClient::BATCH_LIMIT) as $chunk) {
            $body = ['create' => [], 'update' => []];
            foreach ($chunk as [, , [$op, $payload]]) {
                $body[$op][] = $payload;
            }
            $result = $this->woo->post("products/{$wooProductId}/variations/batch", array_filter($body));
            $counters = ['create' => 0, 'update' => 0];

            foreach ($chunk as [$variant, $hash, [$op]]) {
                $row = $result[$op][$counters[$op]++] ?? null;
                if (! $row || isset($row['error'])) {
                    throw new WooApiException("Variation {$variant->sku}: ".($row['error']['message'] ?? 'no answer in the batch'));
                }
                WooLink::remember(WooLink::VARIANT, $variant->id, (int) $row['id'], $wooProductId, $hash);
                $sent++;
            }
        }

        return $sent;
    }

    private function pushOrderStatus(int $orderId): void
    {
        $order = SalesOrder::query()->with(['statusHistory'])->find($orderId);
        if (! $order?->woo_order_id) {
            return;
        }

        $fulfilledAt = $order->statusHistory->whereIn('to_status', ['fulfilled', 'completed'])->first()?->created_at ?? now();
        $this->woo->put("orders/{$order->woo_order_id}", ['status' => 'completed']);
        $this->woo->post("orders/{$order->woo_order_id}/notes", [
            'note' => 'Delivered / collected on '.$fulfilledAt->timezone(config('app.timezone'))->format('d M Y')." (delivery note {$order->number}, invoice {$order->invoice_number}).",
            'customer_note' => false,
        ]);
        $order->forceFill(['woo_status' => 'completed'])->saveQuietly();
        WooSyncLog::write('info', 'status', "Order #{$order->woo_order_number} marked completed in WooCommerce.", ['sales_order_id' => $order->id]);
    }

    /** A new product that already exists in WooCommerce (same SKU) is linked, never duplicated. */
    private function existingBySku(Product $product): ?int
    {
        $sku = $product->variants->first()?->sku;
        if (! $sku) {
            return null;
        }

        $found = collect($this->woo->get('products', ['sku' => $sku]))->first();
        if ($found) {
            return (int) ($found['parent_id'] ?? 0) ?: (int) $found['id'];
        }

        return null;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function attempt(WooSyncItem $item, callable $work, array &$stats): void
    {
        try {
            $work();
            $item->delete();
        } catch (Throwable $e) {
            $this->fail($item, $e, $stats);
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function fail(WooSyncItem $item, Throwable $e, array &$stats): void
    {
        $attempts = $item->attempts + 1;
        $item->forceFill([
            'attempts' => $attempts,
            'available_at' => now()->addMinutes(self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1]),
            'last_error' => mb_substr($e->getMessage(), 0, 500),
        ])->save();
        $stats['failed']++;
        WooSyncLog::write('error', 'push', "{$item->item_key}: {$e->getMessage()}", ['attempts' => $attempts]);
    }

    /** Push everything online again (Online store → Push all). */
    public static function markAll(): int
    {
        $ids = Product::query()->where('publish_online', true)->pluck('id')
            ->merge(WooLink::query()->where('local_type', WooLink::PRODUCT)->pluck('local_id'))->unique();
        foreach ($ids as $id) {
            WooSyncItem::mark(WooSyncItem::PRODUCT, (int) $id);
        }

        return $ids->count();
    }

    /** Variants whose product is linked: for reconcile. */
    public static function linkedVariantIds(): array
    {
        return ProductVariant::query()->whereIn('id', WooLink::query()->where('local_type', WooLink::VARIANT)->pluck('local_id'))->pluck('id')->all();
    }
}
