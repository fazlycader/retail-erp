<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Emails ERROR_ALERT_EMAIL when an unexpected error happens in production, at most once per
 * error type and place every ERROR_ALERT_MINUTES (15) so a broken page doesn't flood the inbox.
 * Expected errors (404, validation, expired sessions…) never alert.
 */
final class ErrorAlerts
{
    public static function report(Throwable $e): void
    {
        $to = config('security.error_alert_email');

        if (! $to || ! app()->isProduction() || ! self::alertable($e)) {
            return;
        }

        $key = 'error-alert:'.sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());

        if (! Cache::add($key, true, now()->addMinutes(max(1, (int) config('security.error_alert_minutes'))))) {
            return;
        }

        try {
            $request = app()->bound('request') ? request() : null;
            $body = implode("\n", array_filter([
                class_basename($e).': '.$e->getMessage(),
                $e->getFile().':'.$e->getLine(),
                $request && ! app()->runningInConsole() ? $request->method().' '.$request->fullUrl() : 'console: '.implode(' ', $_SERVER['argv'] ?? []),
                $request?->user() ? 'User: '.$request->user()->name.' (#'.$request->user()->id.')' : null,
                'Time: '.now()->setTimezone(config('app.timezone'))->toDateTimeString(),
                '',
                'Similar errors are not emailed again for '.config('security.error_alert_minutes').' minutes. Full details are in storage/logs.',
                '',
                collect(explode("\n", $e->getTraceAsString()))->take(15)->implode("\n"),
            ]));

            Mail::raw($body, fn ($m) => $m->to($to)->subject('['.config('app.name').'] Error: '.class_basename($e)));
        } catch (Throwable $mailError) {
            Log::warning('Could not send the error alert email: '.$mailError->getMessage());
        }
    }

    private static function alertable(Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        return ! ($e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException
            || $e instanceof TokenMismatchException
            || $e instanceof ThrottleRequestsException
            || $e instanceof PostTooLargeException);
    }
}
