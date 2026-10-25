<?php

namespace App\Models;

use App\Enums\ReturnOutcome;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesReturn extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outcome' => ReturnOutcome::class,
            'total' => 'integer',
            'settled_amount' => 'integer',
            'business_date' => 'date',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }

    public function exchangeOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'exchange_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
