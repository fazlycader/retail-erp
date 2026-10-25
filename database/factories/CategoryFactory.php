<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numerify('###'),
            'sort_order' => 0,
            'is_active' => true,
            'default_warranty_months' => 12,
            'commission_group' => null,
        ];
    }
}
