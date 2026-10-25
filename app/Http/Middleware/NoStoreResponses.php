<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything Laravel answers is dynamic (staff pages, polling, orders, receipts, agent API,
 * health), so no CDN or browser may store it: Cache-Control no-store, private, plus
 * Vary: Cookie. Static files (build assets, uploaded images) are served by the web server with
 * long cache headers from public/.htaccess. A response that explicitly declares itself
 * public (the /storage fallback route) keeps its own header.
 */
class NoStoreResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (str_contains((string) $response->headers->get('Cache-Control'), 'public')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', trim(($response->headers->get('Vary') ? $response->headers->get('Vary').', ' : '').'Cookie', ', '));

        return $response;
    }
}
