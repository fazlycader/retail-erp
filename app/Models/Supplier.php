<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'contact_person', 'phone', 'secondary_phone', 'email', 'address',
    'payment_terms_days', 'credit_limit', 'bank_details', 'vat_number', 'notes', 'is_active', 'sync_version',
])]
#[Hidden(['bank_details'])]
class Supplier extends Model
{
    protected $attributes = [
        'payment_terms_days' => 0,
        'credit_limit' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'credit_limit' => 'integer',
            'bank_details' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'product_supplier')
            ->withPivot(['supplier_sku', 'last_cost', 'is_preferred'])
            ->withTimestamps();
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function supplierReturns(): HasMany
    {
        return $this->hasMany(SupplierReturn::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
