<?php

namespace App\Services\Woo;

use App\Models\CompanySetting;
use App\Models\WooLink;
use App\Models\WooSyncItem;
use App\Models\WooSyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Safety net (woo:reconcile, hourly): processes every WooCommerce order modified since the last
 * cursor (the ones webhooks missed are created, the rest are no-ops), then compares WooCommerce's
 * stock with ours for linked items and re-pushes any that differ.
 */
class WooReconciler
{
    public function __construct(private WooClient $woo, private OrderIntake $intake) {}

    /**
     * @return array{orders: int, failed: int, stock_mismatches: int}
     */
    public function run(int $budget = 45): array
    {
        $stats = ['orders' => 0, 'failed' => 0, 'stock_mismatches' => 0];
        if (! $this->woo->configured()) {
            return $stats;
        }

        $started = microtime(true);
        $settings = CompanySetting::current();
        $cursor = $settings->woo_order_cursor ?? now()->subDay();
        $newest = $cursor;

        foreach ($this->woo->each('orders', ['modified_after' => $cursor->copy()->utc()->format('Y-m-d\TH:i:s'), 'dates_are_gmt' => 'true', 'orderby' => 'modified', 'order' => 'asc']) as $order) {
            try {
                Cache::lock('woo-order:'.$order['id'], 60)->block(30, fn () => $this->intake->process($order, 'reconcile'));
                $stats['orders']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                WooSyncLog::write('error', 'reconcile', "Woo order #{$order['number']}: {$e->getMessage()}");
            }

            if (filled($order['date_modified_gmt'] ?? null)) {
                $modified = CarbonImmutable::parse($order['date_modified_gmt'].'Z');
                $newest = $modified->gt($newest) ? $modified : $newest;
            }

            if (microtime(true) - $started > $budget) {
                break;
            }
        }

        // The datetime cast stores wall-clock time without converting: keep it in the app timezone.
        $settings->forceFill(['woo_order_cursor' => $newest->setTimezone(config('app.timezone')), 'woo_last_reconcile_at' => now()])->save();

        if (microtime(true) - $started < $budget) {
            $stats['stock_mismatches'] = $this->checkStock($budget - (microtime(true) - $started));
        }

        WooSyncLog::write('info', 'reconcile', "Reconciled {$stats['orders']} order(s), {$stats['stock_mismatches']} stock difference(s) re-pushed.".($stats['failed'] ? " {$stats['failed']} failed." : ''));

        return $stats;
    }

    /** Linked stock items whose WooCommerce stock differs from ours are re-pushed (hashes cleared). */
    private function checkStock(float $budget): int
    {
        $started = microtime(true);
        $mismatches = 0;
        $productLinks = WooLink::query()->where('local_type', WooLink::PRODUCT)->get()->keyBy('woo_id');
        $variantLinks = WooLink::query()->where('local_type', WooLink::VARIANT)->get();
        $byWooId = $variantLinks->keyBy(fn ($l) => $l->woo_id);
        $ours = OnlineStock::forVariants($variantLinks->pluck('local_id')->all());

        $compare = function (array $item, int $productId) use ($byWooId, $ours, &$mismatches) {
            $link = $byWooId->get((int) $item['id']);
            if (! $link || ! ($item['manage_stock'] ?? false)) {
                return;
            }
            if ((int) ($item['stock_quantity'] ?? 0) !== ($ours[$link->local_id] ?? 0)) {
                WooLink::query()->whereIn('local_type', [WooLink::PRODUCT, WooLink::VARIANT])
                    ->where(fn ($q) => $q->where(fn ($p) => $p->where('local_type', WooLink::PRODUCT)->where('local_id', $productId))->orWhere(fn ($v) => $v->where('local_type', WooLink::VARIANT)->where('local_id', $link->local_id)))
                    ->update(['last_pushed_hash' => null]);
                WooSyncItem::mark(WooSyncItem::PRODUCT, $productId);
                $mismatches++;
            }
        };

        foreach ($productLinks->chunk(WooClient::BATCH_LIMIT) as $chunk) {
            foreach ($this->woo->get('products', ['include' => $chunk->keys()->implode(','), 'per_page' => 100]) as $product) {
                $productId = $productLinks->get((int) $product['id'])?->local_id;
                if (! $productId) {
                    continue;
                }
                if (($product['type'] ?? '') === 'variable') {
                    foreach ($this->woo->each("products/{$product['id']}/variations") as $variation) {
                        $compare($variation, $productId);
                    }
                } else {
                    $compare($product, $productId);
                }
                if (microtime(true) - $started > $budget) {
                    return $mismatches;
                }
            }
        }

        return $mismatches;
    }
}
