<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A dirty item waiting for woo:push: a product (its details, prices and every variant's stock are
 * re-sent; unchanged payloads are skipped by hash) or an order's status. One row per item, so many
 * changes to the same product before the next push coalesce into one.
 */
class WooSyncItem extends Model
{
    public const PRODUCT = 'product';

    public const ORDER_STATUS = 'order_status';

    protected $table = 'woo_sync_queue';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'attempts' => 'integer', 'subject_id' => 'integer'];
    }

    /** Mark an item dirty (again): resets backoff, keeps one row per item. */
    public static function mark(string $kind, int $subjectId): void
    {
        $now = now();
        DB::table('woo_sync_queue')->upsert(
            [['item_key' => "{$kind}:{$subjectId}", 'kind' => $kind, 'subject_id' => $subjectId, 'attempts' => 0, 'available_at' => null, 'last_error' => null, 'created_at' => $now, 'updated_at' => $now]],
            ['item_key'],
            ['attempts', 'available_at', 'last_error', 'updated_at'],
        );
    }

    public function scopeDue(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->orderBy('id');
    }
}
