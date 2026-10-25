<?php

namespace App\Console\Commands;

use App\Services\Woo\OrderIntake;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** The token the WordPress plugin uses (ability storefront:read only). Shown once. */
#[Signature('woo:storefront-token {--revoke : Revoke every existing storefront token first}')]
#[Description('Create the connection token for the WordPress plugin')]
class WooStorefrontToken extends Command
{
    public function handle(): int
    {
        $user = OrderIntake::actor();

        if ($this->option('revoke')) {
            $user->tokens()->where('name', 'storefront')->delete();
        }

        $token = $user->createToken('storefront', ['storefront:read'])->plainTextToken;
        $this->line('Paste this into WordPress → Settings → MVRK Connector → Connection token (shown once):');
        $this->line($token);

        return self::SUCCESS;
    }
}
