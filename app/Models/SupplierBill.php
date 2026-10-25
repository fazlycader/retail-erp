<?php

namespace App\Models;

use App\Enums\SupplierBillStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBill extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'subtotal' => 0,
        'tax_total' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'status' => 'unpaid',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'status' => SupplierBillStatus::class,
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function goodsReceipts(): BelongsToMany
    {
        return $this->belongsToMany(GoodsReceipt::class, 'goods_receipt_supplier_bill');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierBillPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function balanceDue(): int
    {
        return max(0, $this->total_amount - $this->paid_amount);
    }
}
