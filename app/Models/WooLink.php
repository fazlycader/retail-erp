<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A local record (product, variant, category, order) ↔ its WooCommerce id. */
class WooLink extends Model
{
    public const PRODUCT = 'product';

    public const VARIANT = 'variant';

    public const CATEGORY = 'category';

    public const ORDER = 'order';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['woo_id' => 'integer', 'woo_parent_id' => 'integer', 'local_id' => 'integer', 'last_synced_at' => 'datetime'];
    }

    public static function for(string $type, int $localId): ?self
    {
        return static::query()->where('local_type', $type)->where('local_id', $localId)->first();
    }

    public static function remember(string $type, int $localId, int $wooId, ?int $parentId = null, ?string $hash = null): self
    {
        $link = static::for($type, $localId) ?? (new static)->forceFill(['local_type' => $type, 'local_id' => $localId]);
        $link->forceFill(['woo_id' => $wooId, 'woo_parent_id' => $parentId, 'last_pushed_hash' => $hash ?? $link->last_pushed_hash, 'last_synced_at' => now()])->save();

        return $link;
    }
}
