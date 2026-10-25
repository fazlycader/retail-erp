<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentSchedule extends Model
{
    protected $table = 'installment_schedule';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal' => 'integer',
            'charge' => 'integer',
            'amount_due' => 'integer',
            'principal_paid' => 'integer',
            'amount_paid' => 'integer',
            'late_fee_charged' => 'integer',
            'late_fee_paid' => 'integer',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(InstallmentAgreement::class, 'installment_agreement_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InstallmentPaymentAllocation::class);
    }
}
