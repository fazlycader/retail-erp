<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'goods_receipt_id', 'purchase_order_line_id', 'product_variant_id',
    'received_qty', 'damaged_qty', 'unit_cost', 'tax_rate_bp',
    'tax_cents', 'line_total', 'serials', 'note',
])]
class GoodsReceiptLine extends Model
{
    protected $attributes = [
        'damaged_qty' => 0,
        'unit_cost' => 0,
        'tax_rate_bp' => 0,
        'tax_cents' => 0,
        'line_total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'received_qty' => 'integer',
            'damaged_qty' => 'integer',
            'unit_cost' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_cents' => 'integer',
            'line_total' => 'integer',
            'serials' => 'array',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
