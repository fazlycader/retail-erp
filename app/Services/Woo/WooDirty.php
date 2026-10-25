<?php

namespace App\Services\Woo;

use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\WooSyncItem;
use Illuminate\Support\Facades\DB;

/**
 * Marks what WooCommerce must hear about, after the change is committed (a rolled-back sale never
 * reaches the shop): any product, variant, image or price change and any stock or reservation change
 * marks the product dirty; a web order reaching fulfilled/completed marks its status for push-back.
 * woo:push does the rest (and skips products that aren't online and were never linked).
 */
final class WooDirty
{
    public static function register(): void
    {
        Product::saved(fn (Product $p) => self::product($p->id));
        Product::deleted(fn (Product $p) => self::product($p->id));
        ProductVariant::saved(fn (ProductVariant $v) => self::product($v->product_id));
        ProductVariant::deleted(fn (ProductVariant $v) => self::product($v->product_id));
        ProductImage::saved(fn (ProductImage $i) => self::product($i->product_id));
        ProductImage::deleted(fn (ProductImage $i) => self::product($i->product_id));

        StockLevel::saved(function (StockLevel $level) {
            if ($level->wasChanged(['on_hand', 'reserved']) || $level->wasRecentlyCreated) {
                self::variant($level->product_variant_id);
            }
        });

        SalesOrder::updated(function (SalesOrder $order) {
            if ($order->woo_order_id && $order->wasChanged('status')
                && in_array($order->status, [SalesOrderStatus::Fulfilled, SalesOrderStatus::Completed], true)) {
                DB::afterCommit(fn () => WooSyncItem::mark(WooSyncItem::ORDER_STATUS, $order->id));
            }
        });
    }

    public static function product(?int $productId): void
    {
        if ($productId) {
            DB::afterCommit(fn () => WooSyncItem::mark(WooSyncItem::PRODUCT, $productId));
        }
    }

    public static function variant(int $variantId): void
    {
        DB::afterCommit(function () use ($variantId) {
            $productId = ProductVariant::withTrashed()->whereKey($variantId)->value('product_id');
            if ($productId) {
                WooSyncItem::mark(WooSyncItem::PRODUCT, (int) $productId);
            }
        });
    }
}
