<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A location's business day: closed by a manager at the end of day, then locked. */
class DayClose extends Model
{
    use BelongsToLocation;

    protected $table = 'business_days';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'closed_at' => 'datetime',
            'warnings' => 'array',
            'report' => 'array',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public static function isLocked(int $locationId, string $date): bool
    {
        return static::query()->where('location_id', $locationId)->whereDate('business_date', $date)->where('status', 'closed')->exists();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
