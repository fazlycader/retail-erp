<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Labour on a job: minutes × hourly rate, or a fixed amount (piece work). */
#[Fillable(['production_job_id', 'user_id', 'worker_name', 'work_date', 'minutes', 'hourly_rate', 'amount', 'note', 'created_by'])]
class JobLabour extends Model
{
    protected $table = 'job_labour';

    protected function casts(): array
    {
        return ['work_date' => 'date', 'minutes' => 'integer', 'hourly_rate' => 'integer', 'amount' => 'integer'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProductionJob::class, 'production_job_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
