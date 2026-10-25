<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an admin account interactively on the server (first login after installing).
 *   php artisan app:create-admin
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--name=} {--email=}';

    protected $description = 'Create an admin user (asks for the password, never shown)';

    public function handle(): int
    {
        if (! Location::query()->exists()) {
            $this->error('No location yet. Run php artisan app:install first.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Name');
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email (used to sign in)'))));
        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirm = (string) $this->secret('Type the password again');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Admin {$user->email} created. Sign in: you will be asked to turn on two-factor login (required for admins), then set a PIN on the Staff page.");

        return self::SUCCESS;
    }
}
