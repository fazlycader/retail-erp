<?php

namespace App\Support;

use App\Enums\Role;
use App\Exceptions\CashActionRefused;
use App\Models\AuditLog;
use App\Models\DayClose;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Once a location's business day is closed, changing its records (voiding its payments, editing
 * or cancelling its orders) is admin-only, and every such edit is audited as day.locked_edit.
 */
final class DayLock
{
    public static function guard(int $locationId, DateTimeInterface|string|null $date, User $actor, string $what, ?Model $subject = null): void
    {
        if ($date === null) {
            return;
        }

        $day = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        if (! DayClose::isLocked($locationId, $day)) {
            return;
        }

        if ($actor->role !== Role::Admin) {
            throw new CashActionRefused("{$day} is closed: only an admin can change its records.", 'day_locked');
        }

        AuditLog::record('day.locked_edit', $subject, "Changed a closed day ({$day}): {$what}", null, ['business_date' => $day], $actor);
    }
}
