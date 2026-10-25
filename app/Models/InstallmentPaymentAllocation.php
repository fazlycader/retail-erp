<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }
}
