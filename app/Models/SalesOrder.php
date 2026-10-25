<?php

namespace App\Models;

use App\Enums\FulfilmentType;
use App\Enums\PaymentStatus;
use App\Enums\SalesChannel;
use App\Enums\SalesOrderKind;
use App\Enums\SalesOrderStatus;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SalesOrder extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected $attributes = [
        'channel' => 'showroom',
        'kind' => 'order',
        'status' => 'draft',
        'payment_status' => 'unpaid',
        'advance_required_cents' => 0,
        'advance_pending' => false,
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
        'paid_amount' => 0,
        'balance_due' => 0,
        'returned_total' => 0,
        'refunded_total' => 0,
        'fulfilment_type' => 'collect',
    ];

    protected function casts(): array
    {
        return [
            'channel' => SalesChannel::class,
            'kind' => SalesOrderKind::class,
            'status' => SalesOrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfilment_type' => FulfilmentType::class,
            'advance_required_cents' => 'integer',
            'advance_pending' => 'boolean',
            'prices_include_tax' => 'boolean',
            'tax_rate_bp' => 'integer',
            'items_gross_total' => 'integer',
            'line_discount_total' => 'integer',
            'subtotal' => 'integer',
            'order_discount_value' => 'integer',
            'order_discount_cents' => 'integer',
            'tax_total' => 'integer',
            'delivery_charge' => 'integer',
            'delivery_fee_cents' => 'integer',
            'rounding_cents' => 'integer',
            'grand_total' => 'integer',
            'paid_amount' => 'integer',
            'balance_due' => 'integer',
            'returned_total' => 'integer',
            'refunded_total' => 'integer',
            'delivery_address' => 'array',
            'requested_delivery_date' => 'date',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'business_date' => 'date',
            'woo_order_id' => 'integer',
            'needs_attention' => 'boolean',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SalesOrderStatusHistory::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function installmentAgreement(): HasOne
    {
        return $this->hasOne(InstallmentAgreement::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class)->orderBy('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
    }

    /** What the customer owes for goods they kept: grand total less returned goods. */
    public function netTotal(): int
    {
        return max(0, $this->grand_total - $this->returned_total);
    }

    /** The public digital copy token, created on first use. */
    public function shareToken(): string
    {
        if (! $this->share_token) {
            $this->forceFill(['share_token' => Str::random(40)])->saveQuietly();
        }

        return $this->share_token;
    }
}
