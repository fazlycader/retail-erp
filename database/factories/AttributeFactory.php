<?php

namespace Database\Factories;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->word()),
            'sort_order' => 0,
        ];
    }
}
