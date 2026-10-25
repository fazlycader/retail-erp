<?php

namespace App\Models;

use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One physical piece of a serialized product (tracked by its unique serial_number).
 * A display piece on the showroom floor is tracked with condition = Display.
 */
#[Fillable([
    'serial_number', 'product_variant_id', 'location_id', 'status', 'condition',
    'unit_cost', 'received_at', 'sold_reference_type', 'sold_reference_id', 'note',
])]
class StockUnit extends Model
{
    protected $attributes = [
        'status' => 'in_stock',
        'condition' => 'new',
        'unit_cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => StockUnitStatus::class,
            'condition' => StockUnitCondition::class,
            'unit_cost' => 'integer',
            'received_at' => 'date',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function soldReference(): MorphTo
    {
        return $this->morphTo();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('id');
    }
}
