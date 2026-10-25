<?php

namespace App\Models;

use App\Enums\SupplierPaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'unallocated_amount' => 0,
        'method' => 'bank_transfer',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'paid_at' => 'date',
            'amount' => 'integer',
            'unallocated_amount' => 'integer',
            'method' => SupplierPaymentMethod::class,
            'cheque_date' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierBillPayment::class);
    }

    public function bills(): BelongsToMany
    {
        return $this->belongsToMany(SupplierBill::class, 'supplier_bill_payment')
            ->withPivot('amount')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
