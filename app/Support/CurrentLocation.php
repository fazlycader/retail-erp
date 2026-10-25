<?php

namespace App\Support;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The location the signed-in user is working at for this request (picked in the top-bar
 * switcher, kept in the session, checked by ResolveCurrentLocation on every request).
 * null + isAll() = an admin looking at "All locations" on a page that allows it.
 */
final class CurrentLocation
{
    public const SESSION_KEY = 'current_location';

    public const ALL = 'all';

    private ?Location $location = null;

    private bool $all = false;

    /** @var Collection<int, Location> */
    private Collection $accessible;

    public function __construct()
    {
        $this->accessible = collect();
    }

    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * @param  Collection<int, Location>  $accessible
     */
    public static function set(?Location $location, bool $all = false, ?Collection $accessible = null): void
    {
        $current = self::instance();
        $current->location = $location;
        $current->all = $all && $location === null;
        $current->accessible = $accessible ?? $current->accessible;
    }

    public static function get(): ?Location
    {
        return self::instance()->location;
    }

    public static function id(): ?int
    {
        return self::instance()->location?->id;
    }

    public static function isAll(): bool
    {
        return self::instance()->all;
    }

    /**
     * Locations the user may switch to (as resolved for this request).
     *
     * @return Collection<int, Location>
     */
    public static function accessible(): Collection
    {
        return self::instance()->accessible;
    }

    /**
     * Location ids the user can see: admins every location, others their home + extra ones.
     *
     * @return array<int, int>|null null = no restriction (admins)
     */
    public static function visibleIdsFor(User $user): ?array
    {
        return $user->role->seesAllLocations() ? null : $user->locationIds();
    }
}
