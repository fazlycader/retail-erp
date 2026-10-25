<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Showroom',
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'type' => LocationType::Showroom,
            'address' => fake()->streetAddress(),
            'phone' => '+9411'.fake()->numerify('#######'),
            'is_active' => true,
        ];
    }

    public function warehouse(): static
    {
        return $this->state(fn () => ['type' => LocationType::Warehouse, 'name' => fake()->unique()->city().' Warehouse']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
