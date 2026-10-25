<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'is_custom' => false,
        'is_service' => false,
        'discount_value' => 0,
        'discount_cents' => 0,
        'line_subtotal' => 0,
        'order_discount_share' => 0,
        'tax_cents' => 0,
        'line_total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'is_service' => 'boolean',
            'attributes_snapshot' => 'array',
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_value' => 'integer',
            'discount_cents' => 'integer',
            'line_subtotal' => 'integer',
            'order_discount_share' => 'integer',
            'tax_cents' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function customSpec(): BelongsTo
    {
        return $this->belongsTo(CustomSpec::class);
    }
}
