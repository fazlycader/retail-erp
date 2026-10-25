<?php

namespace App\Services\Production;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * The catalogue item a one-off custom piece is stocked as when its line has no product of its own
 * (a hidden "Custom-made piece", SKU CUSTOM-PIECE, in a hidden "Custom pieces" category). Each
 * finished piece is a stock unit of it, carrying its own cost and reserved for its order.
 */
final class CustomPiece
{
    public const SKU = 'CUSTOM-PIECE';

    public static function variant(): ProductVariant
    {
        if ($variant = ProductVariant::withTrashed()->where('sku', self::SKU)->first()) {
            return $variant;
        }

        $category = Category::query()->firstOrCreate(['slug' => 'custom-pieces'], ['name' => 'Custom pieces', 'is_active' => false]);
        $product = Product::query()->create([
            'category_id' => $category->id, 'name' => 'Custom-made piece', 'slug' => 'custom-made-piece',
            'type' => ProductType::MadeToOrder, 'is_active' => false, 'publish_online' => false,
        ]);

        return $product->variants()->create(['sku' => self::SKU, 'name' => '', 'combination_key' => '', 'price' => 0, 'min_price' => 0, 'is_active' => false]);
    }
}
