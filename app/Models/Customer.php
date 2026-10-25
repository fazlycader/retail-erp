<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Rules\SriLankanPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'type' => 'individual',
        'sms_opt_out' => false,
        'credit_limit' => 0,
        'is_blacklisted' => false,
        'total_orders_count' => 0,
        'total_spent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'addresses' => 'array',
            'sms_opt_out' => 'boolean',
            'credit_limit' => 'integer',
            'is_blacklisted' => 'boolean',
            'first_purchase_at' => 'datetime',
            'last_purchase_at' => 'datetime',
            'total_orders_count' => 'integer',
            'total_spent' => 'integer',
        ];
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function installmentAgreements(): HasMany
    {
        return $this->hasMany(InstallmentAgreement::class);
    }

    public function displayPhone(): ?string
    {
        return SriLankanPhone::display($this->phone);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function defaultAddress(): ?array
    {
        $addresses = $this->addresses ?? [];
        if (empty($addresses)) {
            return null;
        }

        foreach ($addresses as $addr) {
            if (! empty($addr['is_default'])) {
                return $addr;
            }
        }

        return $addresses[0];
    }
}
