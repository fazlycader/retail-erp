<?php

namespace App\Models\Concerns;

use App\Models\Location;
use App\Models\User;
use App\Support\CurrentLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * For location-specific models (stock, invoices, GRNs…). Fills location_id on create from the
 * current location (top-bar switcher), falling back to the signed-in user's home location.
 * Scope queries with ->forLocation() (current location; "All locations" = every location the
 * user can see) or ->visibleTo($user).
 */
trait BelongsToLocation
{
    public static function bootBelongsToLocation(): void
    {
        static::creating(function ($model) {
            $model->location_id ??= CurrentLocation::id() ?? Auth::user()?->home_location_id;
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeForLocation(Builder $query, Location|int|null $location = null): Builder
    {
        $column = $this->qualifyColumn('location_id');

        if ($location === null && CurrentLocation::isAll()) {
            $user = Auth::user();
            $ids = $user ? CurrentLocation::visibleIdsFor($user) : [];

            return $ids === null ? $query : $query->whereIn($column, $ids);
        }

        $id = $location instanceof Location ? $location->id : ($location ?? CurrentLocation::id());

        return $query->where($column, $id);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = CurrentLocation::visibleIdsFor($user);

        return $ids === null ? $query : $query->whereIn($this->qualifyColumn('location_id'), $ids);
    }
}
