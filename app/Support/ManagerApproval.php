<?php

namespace App\Support;

use App\Enums\Role;
use App\Exceptions\ApprovalRefused;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Pick a manager, enter their PIN" for actions a cashier can't do alone (discounts over the
 * limit, returns, voids, large adjustments). The approver must be an active admin, or a manager
 * of the location the action happens at. 5 wrong tries a minute per staff member; wrong PINs
 * are audit logged under "{action}_denied". PIN lockout applies (User::attemptPin).
 */
class ManagerApproval
{
    public const MAX_ATTEMPTS = 5;

    /**
     * The approving manager, or throws. Call only when approval is actually needed.
     */
    public static function verify(User $actor, int $locationId, ?int $approverId, ?string $pin, string $action, ?Model $subject, string $what): User
    {
        if (! $approverId || blank($pin)) {
            throw new ApprovalRefused('A manager needs to approve this. Choose a manager and enter their PIN.', 'approval_required');
        }

        $key = 'manager-approval:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ApprovalRefused('Too many PIN attempts. Wait a minute and try again.', 'too_many', 429);
        }

        $approver = User::query()->find($approverId);

        if ($approver?->pinLocked()) {
            throw new ApprovalRefused("{$approver->name}'s PIN is locked for a few minutes after too many wrong tries.", 'pin_locked');
        }

        if (! $approver || $approver->is($actor) || ! $approver->role->canOverride() || ! $approver->is_active
            || ! $approver->worksAt($locationId) || ! $approver->attemptPin((string) $pin, $what)) {
            RateLimiter::hit($key, 60);
            AuditLog::record("{$action}_denied", $subject, "Wrong manager PIN for {$what}", null, ['approver_id' => $approverId], $actor);

            throw new ApprovalRefused('Manager PIN not accepted.', 'approval_required');
        }

        RateLimiter::clear($key);

        return $approver;
    }

    /**
     * Active admins and this location's managers with a PIN, for the approval picker.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function approvers(int $locationId): array
    {
        return User::query()->active()->whereNotNull('pin')
            ->where(fn ($q) => $q->where('role', Role::Admin->value)
                ->orWhere(fn ($q) => $q->where('role', Role::Manager->value)->atLocation($locationId)))
            ->orderBy('name')->get(['id', 'name'])
            ->map->only(['id', 'name'])->values()->all();
    }
}
