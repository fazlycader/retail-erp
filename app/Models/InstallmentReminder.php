<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentReminder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }
}
