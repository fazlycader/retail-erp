<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentLateFee extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'charged_on' => 'date'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }
}
