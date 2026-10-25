<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'location_id', 'reason', 'business_date', 'total_value', 'note', 'created_by', 'approved_by'])]
class StockAdjustment extends Model
{
    use BelongsToLocation;

    protected function casts(): array
    {
        return [
            'reason' => AdjustmentReason::class,
            'business_date' => 'date',
            'total_value' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
