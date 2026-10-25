<?php

namespace App\Models;

use App\Enums\ChequeStatus;
use App\Enums\PaymentRecordStatus;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A payment receipt. Cheques count as paid until they bounce; voided payments never count.
 */
class Payment extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'completed',
        'change_given' => 0,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tendered' => 'integer',
            'change_given' => 'integer',
            'cheque_date' => 'date',
            'cheque_status' => ChequeStatus::class,
            'cheque_status_at' => 'datetime',
            'business_date' => 'date',
            'status' => PaymentRecordStatus::class,
            'voided_at' => 'datetime',
            'imported' => 'boolean',
        ];
    }

    /** Payments that count towards what the customer has paid. */
    public function scopeCounted(Builder $query): void
    {
        $query->where('status', PaymentRecordStatus::Completed->value)
            ->where(fn ($q) => $q->whereNull('cheque_status')->orWhere('cheque_status', '!=', ChequeStatus::Bounced->value));
    }

    public function counts(): bool
    {
        return $this->status === PaymentRecordStatus::Completed && $this->cheque_status !== ChequeStatus::Bounced;
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function installmentAgreement(): BelongsTo
    {
        return $this->belongsTo(InstallmentAgreement::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InstallmentPaymentAllocation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function driverCollection(): HasOne
    {
        return $this->hasOne(DriverCollection::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function bankDeposit(): BelongsTo
    {
        return $this->belongsTo(BankDeposit::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
