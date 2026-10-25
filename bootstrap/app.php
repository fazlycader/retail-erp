<?php

use App\Http\Controllers\StorageFallbackController;
use App\Http\Controllers\System\HealthController;
use App\Http\Controllers\System\OpcacheResetController;
use App\Http\Middleware\AllowsAllLocations;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoStoreResponses;
use App\Http\Middleware\ResolveCurrentLocation;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ErrorAlerts;
use App\Support\UploadLimit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Fallback for hosts that can't follow the public/storage symlink (no session, no cookies).
            Route::get('/storage/{path}', StorageFallbackController::class)
                ->where('path', '[A-Za-z0-9/_\-.]+')
                ->name('storage.fallback');

            // Uptime monitor / deploy smoke test, and the post-deploy OPcache reset (token-protected).
            Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');
            Route::post('/deploy/opcache-reset', OpcacheResetController::class)->middleware('throttle:10,1')->name('deploy.opcache');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every response: HTTPS, security headers, never cached by a CDN.
        $middleware->prepend(ForceHttps::class);
        $middleware->append([SecurityHeaders::class, NoStoreResponses::class]);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            EnsureTwoFactor::class,
            ResolveCurrentLocation::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'abilities' => CheckAbilities::class,
            ResolveCurrentLocation::ALLOWS_ALL => AllowsAllLocations::class,
        ]);

        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Throttled email to ERROR_ALERT_EMAIL in production.
        $exceptions->reportable(fn (Throwable $e) => ErrorAlerts::report($e));

        // PHP discards bodies over post_max_size before Laravel runs, so validation can't explain it.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return null;
            }

            return back()->with('error', 'That file is too large to upload. Please choose an image under '.UploadLimit::describe(UploadLimit::bytes()).'.');
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
