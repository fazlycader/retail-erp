<?php

namespace App\Facades;

use App\Services\Integrations\IntegrationStore;
use Illuminate\Support\Facades\Facade;

/**
 * Integration credentials (WooCommerce, …). Use this INSTEAD of the env helper or config() for anything declared in CredentialRegistry:
 *
 *   Integrations::get('WOO_STORE_URL')                // ?string
 *   Integrations::filled('WOO_CONSUMER_KEY', ...)     // all of them set?
 *
 * @method static ?string get(string $key)
 * @method static bool enabled(string $key)
 * @method static bool filled(string ...$keys)
 * @method static \App\Models\IntegrationCredential set(string $key, ?string $value, ?\App\Models\User $by)
 * @method static \App\Models\IntegrationCredential undo(string $key, ?\App\Models\User $by)
 * @method static void flush()
 *
 * @see IntegrationStore
 */
class Integrations extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IntegrationStore::class;
    }
}
