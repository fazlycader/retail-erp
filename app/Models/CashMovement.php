<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use BelongsToLocation;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'integer',
            'business_date' => 'date',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
