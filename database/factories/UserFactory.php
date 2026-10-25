<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+947'.fake()->numerify('########'),
            'role' => Role::Cashier,
            'home_location_id' => fn () => Location::query()->oldest('id')->value('id') ?? Location::factory()->create()->id,
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        // The home location is always in the location_user pivot too.
        return $this->afterCreating(function (User $user) {
            if ($user->home_location_id) {
                $user->locations()->syncWithoutDetaching([$user->home_location_id]);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function manager(): static
    {
        return $this->role(Role::Manager);
    }

    public function cashier(): static
    {
        return $this->role(Role::Cashier);
    }

    public function storekeeper(): static
    {
        return $this->role(Role::Storekeeper);
    }

    public function accountant(): static
    {
        return $this->role(Role::Accountant);
    }

    /** Home location first, then any extra locations. */
    public function at(Location $home, Location ...$extra): static
    {
        return $this->state(fn (array $attributes) => ['home_location_id' => $home->id])
            ->afterCreating(fn (User $user) => $user->locations()->syncWithoutDetaching(array_map(fn (Location $l) => $l->id, $extra)));
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function withPin(string $pin = '1234'): static
    {
        return $this->state(fn (array $attributes) => ['pin' => $pin]);
    }
}
