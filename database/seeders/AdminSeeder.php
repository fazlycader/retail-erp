<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The first admin: ADMIN_EMAIL / ADMIN_PASSWORD (defaults admin@example.com / "password"
 * outside production; production needs ADMIN_PASSWORD, or prints a random one once).
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $password = env('ADMIN_PASSWORD') ?: (app()->isProduction() ? Str::password(16) : 'password');
        $home = Location::query()->where('code', 'HO')->value('id') ?? Location::query()->oldest('id')->value('id');

        $admin = User::query()->forceCreate([
            'name' => env('ADMIN_NAME', 'Admin'),
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
            'home_location_id' => $home,
            'email_verified_at' => now(),
        ]);
        $admin->assignLocations($home);

        if (app()->isProduction() && ! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Admin {$email} created with password: {$password} (change it after signing in).");
        }
    }
}
