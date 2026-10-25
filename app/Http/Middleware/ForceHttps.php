<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** In production every request is HTTPS: plain-HTTP GETs are redirected, other methods refused. */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('security.force_https') && ! $request->isSecure()) {
            if (! $request->isMethodSafe()) {
                abort(426, 'Use HTTPS.');
            }

            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
