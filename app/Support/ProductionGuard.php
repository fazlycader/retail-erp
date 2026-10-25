<?php

namespace App\Support;

use RuntimeException;

/**
 * Refuses to boot a production install with unsafe or missing settings, so a broken .env
 * fails loudly at deploy time instead of quietly running with debug pages or the wrong queue.
 */
final class ProductionGuard
{
    /** Commands that must work even with an incomplete .env (to fix it). */
    private const ALLOWED_COMMANDS = ['key:generate', 'config:clear', 'cache:clear', 'optimize:clear', 'about', 'env', 'list', 'help', 'down', 'up', 'package:discover'];

    /**
     * @return array<int, string> problems (empty = fine)
     */
    public static function problems(): array
    {
        $problems = [];
        $db = config('database.connections.'.config('database.default'));

        if (! config('app.key')) {
            $problems[] = 'APP_KEY is empty (run php artisan key:generate).';
        }

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false in production.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL must be your https:// address.';
        }

        if (($db['driver'] ?? null) !== 'mysql' || blank($db['database'] ?? null) || blank($db['username'] ?? null)) {
            $problems[] = 'DB_CONNECTION=mysql with DB_DATABASE and DB_USERNAME is required.';
        }

        if (config('queue.default') !== 'database') {
            $problems[] = 'QUEUE_CONNECTION must be database (shared hosting has no Redis/Supervisor).';
        }

        if (! in_array(config('cache.default'), ['database', 'file'], true)) {
            $problems[] = 'CACHE_STORE must be database or file.';
        }

        if (config('session.driver') !== 'database') {
            $problems[] = 'SESSION_DRIVER must be database.';
        }

        if (config('session.secure') === false) {
            $problems[] = 'SESSION_SECURE_COOKIE must not be false in production.';
        }

        if (blank(config('mail.from.address')) || config('mail.default') === 'array') {
            $problems[] = 'Mail is not configured (MAIL_MAILER=smtp and MAIL_FROM_ADDRESS).';
        }

        if (config('app.timezone') !== 'Asia/Colombo') {
            $problems[] = 'APP_TIMEZONE must be Asia/Colombo (business days and reports depend on it).';
        }

        return $problems;
    }

    public static function check(): void
    {
        if (app()->runningInConsole() && in_array($_SERVER['argv'][1] ?? '', self::ALLOWED_COMMANDS, true)) {
            return;
        }

        if ($problems = self::problems()) {
            throw new RuntimeException("Production configuration problems:\n - ".implode("\n - ", $problems));
        }
    }
}
