<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_return_id', 'product_variant_id', 'stock_unit_id',
    'quantity', 'unit_cost', 'line_total', 'reason',
])]
class SupplierReturnLine extends Model
{
    protected $attributes = [
        'unit_cost' => 0,
        'line_total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
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
