<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(3, true));

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numerify('###'),
            'brand' => 'Damro',
            'description' => '<p>Solid hardwood frame with high-density foam.</p>',
            'type' => ProductType::Stock,
            'width_mm' => 1800,
            'depth_mm' => 850,
            'height_mm' => 800,
            'weight_g' => 38000,
            'assembly_required' => false,
            'requires_delivery' => true,
            'warranty_months' => null,
            'is_serialized' => false,
            'publish_online' => false,
            'is_active' => true,
            'tags' => ['living room'],
        ];
    }
}
