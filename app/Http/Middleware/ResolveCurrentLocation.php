<?php

namespace App\Http\Middleware;

use App\Models\Location;
use App\Support\CurrentLocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out the current location on every request: the one picked in the switcher (session),
 * if the user still has it and it's active, else their home location, else their first one.
 * "All locations" (admins) only applies on routes with the all-locations middleware; other
 * pages use the home location for that request and keep the choice for the next "All" page.
 */
class ResolveCurrentLocation
{
    /** Route middleware alias that lets a page show "All locations". */
    public const ALLOWS_ALL = 'all-locations';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $accessible = Location::query()->accessibleTo($user)->orderBy('name')->get();
        $chosen = $request->session()->get(CurrentLocation::SESSION_KEY);
        $canAll = $user->role->seesAllLocations();

        if ($chosen === CurrentLocation::ALL && $canAll && self::allowsAll($request)) {
            CurrentLocation::set(null, all: true, accessible: $accessible);

            return $next($request);
        }

        $location = $accessible->firstWhere('id', is_numeric($chosen) ? (int) $chosen : null)
            ?? $accessible->firstWhere('id', $user->home_location_id)
            ?? $accessible->first();

        if ($location === null) {
            // No active location at all (e.g. a deactivated home and no others): only signing out works.
            abort_unless($request->routeIs('logout'), 403, 'Your account is not linked to an active location. Ask a manager.');

            return $next($request);
        }

        if ($chosen !== CurrentLocation::ALL || ! $canAll) {
            $request->session()->put(CurrentLocation::SESSION_KEY, $location->id);
        }

        CurrentLocation::set($location, accessible: $accessible);

        return $next($request);
    }

    public static function allowsAll(Request $request): bool
    {
        return in_array(self::ALLOWS_ALL, $request->route()?->gatherMiddleware() ?? [], true);
    }
}
