<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_count_id', 'product_variant_id', 'system_qty', 'counted_qty', 'variance_qty', 'unit_cost', 'variance_value', 'counted_by', 'counted_at', 'note'])]
class StockCountLine extends Model
{
    protected $attributes = [
        'system_qty' => 0,
        'variance_qty' => 0,
        'unit_cost' => 0,
        'variance_value' => 0,
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'integer',
            'counted_qty' => 'integer',
            'variance_qty' => 'integer',
            'unit_cost' => 'integer',
            'variance_value' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
