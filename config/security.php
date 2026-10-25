<?php

return [
    // Content-Security-Policy header (App\Http\Middleware\SecurityHeaders). On by default.
    'csp' => (bool) env('SECURITY_CSP', true),

    // Redirect HTTP → HTTPS. Defaults to on in production.
    'force_https' => (bool) env('FORCE_HTTPS', env('APP_ENV') === 'production'),

    // Which proxies to trust for X-Forwarded-* (client IP, HTTPS):
    //   empty           Hostinger without the CDN (LiteSpeed terminates SSL itself)
    //   REMOTE_ADDR     behind Hostinger CDN (trust the hop in front of us)
    //   a,b,c           specific proxy IPs/ranges
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Two-factor login is required for admins and accountants (App\Support\TwoFactor::required).
    'require_two_factor' => (bool) env('REQUIRE_TWO_FACTOR', true),

    // Error alert emails (App\Support\ErrorAlerts): recipient and how often per error type.
    'error_alert_email' => env('ERROR_ALERT_EMAIL'),
    'error_alert_minutes' => (int) env('ERROR_ALERT_MINUTES', 15),

    // /health (uptime monitor) and the post-deploy OPcache reset.
    'health_token' => env('HEALTH_TOKEN'),
    'opcache_reset_token' => env('OPCACHE_RESET_TOKEN'),
];
