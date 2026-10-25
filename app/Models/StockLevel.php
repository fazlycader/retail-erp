<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current stock on hand and reserved at one location for one variant (milli-units).
 * Never write directly: only App\Services\Inventory\StockService and ReservationService
 * touch this table so stock_levels always matches the immutable stock_movements ledger.
 */
#[Fillable(['product_variant_id', 'location_id', 'on_hand', 'reserved', 'sync_version'])]
class StockLevel extends Model
{
    protected $attributes = [
        'on_hand' => 0,
        'reserved' => 0,
        'sync_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'sync_version' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Free to sell or transfer (milli-units). */
    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }
}
