<?php

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttributeValue>
 */
class AttributeValueFactory extends Factory
{
    public function definition(): array
    {
        $value = ucfirst($this->faker->unique()->word());

        return [
            'attribute_id' => Attribute::factory(),
            'value' => $value,
            'code' => AttributeValue::codeFor($value),
            'swatch_color' => null,
            'sort_order' => 0,
        ];
    }
}
