<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marker for routes where admins may view "All locations" (alias "all-locations").
 * ResolveCurrentLocation looks for it; it does nothing itself.
 */
class AllowsAllLocations
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
