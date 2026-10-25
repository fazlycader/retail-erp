<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentAgreement extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'financed_amount' => 'integer',
            'down_payment' => 'integer',
            'charges' => 'integer',
            'tax_cents' => 'integer',
            'tax_rate_bp_snapshot' => 'integer',
            'total_payable' => 'integer',
            'early_settlement_rebate' => 'integer',
            'charge_rebate_bp_snapshot' => 'integer',
            'ownership_until_paid_snapshot' => 'boolean',
            'late_fee_cents_snapshot' => 'integer',
            'late_fee_bp_snapshot' => 'integer',
            'grace_days_snapshot' => 'integer',
            'term_months' => 'integer',
            'start_date' => 'date',
            'completed_at' => 'date',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class)->orderBy('due_date');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(InstallmentGuarantor::class);
    }

    public function contactLogs(): HasMany
    {
        return $this->hasMany(InstallmentContactLog::class)->latest();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
