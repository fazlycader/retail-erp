<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What the WooCommerce sync did or couldn't do (shown on the Online store page). */
class WooSyncLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'woo_sync_log';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /**
     * @param  array<string, mixed>  $context  never credentials or full payloads
     */
    public static function write(string $level, string $action, string $message, array $context = []): void
    {
        static::query()->forceCreate(['level' => $level, 'action' => $action, 'message' => mb_substr($message, 0, 500), 'context' => $context ?: null, 'created_at' => now()]);
    }
}
