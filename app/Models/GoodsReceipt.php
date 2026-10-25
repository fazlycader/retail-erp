<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected $attributes = [
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
        'total_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'received_at' => 'datetime',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function bills(): BelongsToMany
    {
        return $this->belongsToMany(SupplierBill::class, 'goods_receipt_supplier_bill');
    }
}
