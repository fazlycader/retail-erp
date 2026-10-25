<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'draft',
        'subtotal' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 0,
        'total_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'total_amount' => 'integer',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
