<?php

namespace App\Http\Middleware;

use App\Support\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins and accountants (security.require_two_factor, on by default) without two-factor login
 * are sent to set it up before they can use anything else.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasTwoFactor() && TwoFactor::required($user)
            && ! $request->routeIs('two-factor.*', 'logout', 'terminal.*')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Set up two-factor login first.'], 403)
                : redirect()->route('two-factor.show')->with('error', 'Set up two-factor login to continue.');
        }

        return $next($request);
    }
}
