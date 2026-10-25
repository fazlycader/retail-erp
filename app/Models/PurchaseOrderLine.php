<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'received_qty' => 0,
        'unit_cost' => 0,
        'discount_cents' => 0,
        'tax_rate_bp' => 0,
        'tax_cents' => 0,
        'line_total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sales_order_line_id' => 'integer',
            'ordered_qty' => 'integer',
            'received_qty' => 'integer',
            'unit_cost' => 'integer',
            'discount_cents' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_cents' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function remainingQty(): int
    {
        return max(0, $this->ordered_qty - $this->received_qty);
    }
}
