<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Immutable ledger of every stock movement. Written exclusively by StockService.
 * Updating or deleting a movement row is forbidden.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'unit_cost' => 'integer',
            'business_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Stock movements are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Stock movements are immutable and cannot be deleted.');
        });
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
