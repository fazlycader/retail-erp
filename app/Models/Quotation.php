<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'draft',
        'prices_include_tax' => true,
        'tax_rate_bp' => 0,
        'items_gross_total' => 0,
        'line_discount_total' => 0,
        'subtotal' => 0,
        'order_discount_value' => 0,
        'order_discount_cents' => 0,
        'tax_total' => 0,
        'delivery_charge' => 0,
        'rounding_cents' => 0,
        'grand_total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'valid_until' => 'date',
            'follow_up_date' => 'date',
            'prices_include_tax' => 'boolean',
            'tax_rate_bp' => 'integer',
            'items_gross_total' => 'integer',
            'line_discount_total' => 'integer',
            'subtotal' => 'integer',
            'order_discount_value' => 'integer',
            'order_discount_cents' => 'integer',
            'tax_total' => 'integer',
            'delivery_charge' => 'integer',
            'rounding_cents' => 'integer',
            'grand_total' => 'integer',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function discountApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function convertedSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }
}
