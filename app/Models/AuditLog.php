<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use App\Support\CurrentLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Who did what: staff changes, settings, PIN lockouts, manager overrides…
 * Write with AuditLog::record(); never updated. The location is the subject's (when it has
 * one), else the location the user was working at.
 */
class AuditLog extends Model
{
    use BelongsToLocation;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?Model $subject, ?string $summary = null, ?array $before = null, ?array $after = null, ?User $user = null): self
    {
        $user ??= Auth::user();

        $locationId = match (true) {
            $subject instanceof Location => $subject->id,
            $subject instanceof User => $subject->home_location_id,
            default => $subject?->getAttribute('location_id'),
        };

        return static::create([
            'location_id' => $locationId ?? CurrentLocation::id() ?? $user?->home_location_id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => $summary === null ? null : mb_substr($summary, 0, 255),
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
