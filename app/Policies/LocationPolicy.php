<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

/**
 * Admins create, edit and deactivate locations. Managers edit the details and settings of
 * the locations they work at.
 */
class LocationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->canOverride();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, Location $location): bool
    {
        return $actor->isAdmin() || ($actor->role->canOverride() && $actor->worksAt($location));
    }

    public function toggleActive(User $actor, Location $location): bool
    {
        return $actor->isAdmin();
    }
}
