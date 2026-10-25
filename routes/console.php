<?php

use App\Http\Controllers\System\HealthController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Everything below runs from ONE cron entry on the shared host, every minute:
|   php /path/to/app/artisan schedule:run >> /dev/null 2>&1
| No Supervisor: the database queue is worked here and each run stops when the queue is empty.
*/

// Heartbeat for /health ("is cron running?").
Schedule::call(fn () => Cache::forever(HealthController::HEARTBEAT_KEY, now()->timestamp))->everyMinute()->name('heartbeat');

// Queued jobs (daily email, large exports, notifications). Stops when empty or after 50 s.
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--tries=3', '--timeout=45'])
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Report summaries for the last days (late returns, edits).
Schedule::command('reports:summarize', ['--days=3'])->hourly()->withoutOverlapping(30);

// The owner's daily email at the configured time if it didn't go out after the last day close.
Schedule::command('reports:daily-email')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('reports:prune-exports')->dailyAt('04:30');

// Backups (database + uploaded images) to the BACKUP_S3_* bucket, then retention and a health check.
Schedule::command('backup:clean')->dailyAt('02:30')->withoutOverlapping(60);
Schedule::command('backup:run')->dailyAt('03:00')->withoutOverlapping(60);
Schedule::command('backup:monitor')->dailyAt('09:00');

// Housekeeping.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->dailyAt('04:40');
Schedule::command('auth:clear-resets')->dailyAt('05:00');

// WooCommerce (step 10): push dirty products/stock/order statuses every minute; safety-net reconcile hourly.
Schedule::command('woo:push')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('woo:reconcile')->hourlyAt(17)->withoutOverlapping(30);

// Installment late fees and due/arrears reminders; dedupe ledgers make reruns safe.
Schedule::command('installments:daily')->dailyAt('08:00')->withoutOverlapping(30);
