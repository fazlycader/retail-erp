<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response, and a Content-Security-Policy with a per-request nonce
 * (Vite, Ziggy's @routes and the inline scripts on print pages carry it). Inline style
 * attributes stay allowed (print layouts, Vue :style); fonts come from fonts.bunny.net.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (config('security.csp') && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $connect = ["'self'"];
        $script = ["'self'", "'nonce-{$nonce}'"];
        $img = ["'self'", 'data:', 'blob:'];

        // Uploaded photos/logos are linked by absolute URL (APP_URL or PUBLIC_DISK_URL), which
        // may differ from the address in the browser (www vs bare domain, a separate image host).
        foreach ([config('app.url'), config('filesystems.disks.public.url')] as $url) {
            $origin = $this->origin($url);

            if ($origin !== null && ! in_array($origin, $img, true)) {
                $img[] = $origin;
            }
        }

        // Vite dev server (local only).
        if (Vite::isRunningHot()) {
            $hot = rtrim((string) @file_get_contents(public_path('hot')));
            $script[] = $hot;
            $connect[] = $hot;
            $connect[] = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $hot);
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            'img-src '.implode(' ', $img),
            'connect-src '.implode(' ', $connect),
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    private function origin(?string $url): ?string
    {
        $parts = parse_url((string) $url);

        if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
