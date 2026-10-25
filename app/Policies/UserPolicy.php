<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Staff management. Admins manage everyone; managers manage non-admin staff whose home
 * location is one of their own locations.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->canManageStaff();
    }

    public function create(User $actor): bool
    {
        return $actor->role->canManageStaff();
    }

    public function update(User $actor, User $staff): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return $actor->role->canManageStaff()
            && $staff->role !== Role::Admin
            && in_array($staff->home_location_id, $actor->locationIds(), true);
    }

    /**
     * Nobody can deactivate themselves (prevents locking the last admin out).
     */
    public function toggleActive(User $actor, User $staff): bool
    {
        return $actor->isNot($staff) && $this->update($actor, $staff);
    }
}
