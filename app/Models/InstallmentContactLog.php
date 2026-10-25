<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentContactLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['promise_to_pay_on' => 'date'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(InstallmentAgreement::class, 'installment_agreement_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
