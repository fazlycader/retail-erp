<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'SKU-'.$this->faker->unique()->bothify('????-####'),
            'name' => '',
            'combination_key' => '',
            'price' => 12500000,
            'min_price' => 11000000,
            'online_price' => null,
            'average_cost' => 8000000,
            'last_cost' => 8200000,
            'reorder_level' => 2000,
            'reorder_qty' => 5000,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
