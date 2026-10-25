<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_adjustment_id', 'product_variant_id', 'stock_unit_id', 'quantity', 'unit_cost', 'line_value', 'note'])]
class StockAdjustmentLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'line_value' => 'integer',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
