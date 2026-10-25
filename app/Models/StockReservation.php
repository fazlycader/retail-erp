<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['product_variant_id', 'location_id', 'quantity', 'stock_unit_id', 'reserved_for_type', 'reserved_for_id', 'status', 'user_id', 'note', 'fulfilled_at', 'released_at'])]
class StockReservation extends Model
{
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => ReservationStatus::class,
            'fulfilled_at' => 'datetime',
            'released_at' => 'datetime',
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

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function reservedFor(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
