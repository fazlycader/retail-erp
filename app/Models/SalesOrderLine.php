<?php

namespace App\Models;

use App\Enums\SalesLineSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SalesOrderLine extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'is_custom' => false,
        'is_service' => false,
        'source' => 'stock',
        'reserved_qty' => 0,
        'fulfilled_qty' => 0,
        'returned_qty' => 0,
        'min_price_snapshot' => 0,
        'unit_cost_snapshot' => 0,
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
            'source' => SalesLineSource::class,
            'quantity' => 'integer',
            'reserved_qty' => 'integer',
            'fulfilled_qty' => 'integer',
            'returned_qty' => 'integer',
            'unit_price' => 'integer',
            'min_price_snapshot' => 'integer',
            'unit_cost_snapshot' => 'integer',
            'discount_value' => 'integer',
            'discount_cents' => 'integer',
            'line_subtotal' => 'integer',
            'order_discount_share' => 'integer',
            'tax_cents' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function fulfilLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'fulfil_location_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function reservations(): MorphMany
    {
        return $this->morphMany(StockReservation::class, 'reserved_for');
    }

    public function customSpec(): BelongsTo
    {
        return $this->belongsTo(CustomSpec::class);
    }

    public function productionJob(): HasOne
    {
        return $this->hasOne(ProductionJob::class);
    }

    public function remainingQty(): int
    {
        return max(0, $this->quantity - $this->fulfilled_qty);
    }
}
