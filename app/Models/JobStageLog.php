<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The job's history: stage moves, notes, photos, completion, supplier updates. Never edited. */
#[Fillable(['production_job_id', 'from_stage_id', 'to_stage_id', 'event', 'user_id', 'note', 'photos', 'created_at'])]
class JobStageLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['photos' => 'array', 'created_at' => 'datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProductionJob::class, 'production_job_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(ProductionStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(ProductionStage::class, 'to_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
