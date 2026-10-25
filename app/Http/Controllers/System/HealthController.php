<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * GET /health for uptime monitors (UptimeRobot) and the deploy smoke test. Needs HEALTH_TOKEN
 * (header X-Health-Token or ?token=). 200 = ok or degraded (warnings), 503 = something is down.
 *
 * ?scope=deploy checks only what a fresh release needs (database, storage, app boot), so the
 * first deploy doesn't roll back before cron is set up.
 */
class HealthController extends Controller
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('security.health_token');
        $given = (string) ($request->header('X-Health-Token') ?? $request->query('token', ''));

        abort_if($token === '' || ! hash_equals($token, $given), 404);

        $checks = ['app' => ['status' => 'ok', 'version' => $this->version()]];
        $checks['database'] = $this->check(fn () => DB::select('select 1') && true);
        $checks['storage'] = $this->check(function () {
            $file = 'framework/health-'.bin2hex(random_bytes(4));
            file_put_contents(storage_path($file), 'ok');
            $ok = file_get_contents(storage_path($file)) === 'ok';
            unlink(storage_path($file));

            return $ok && is_writable(storage_path('logs')) && Storage::disk('public')->put('.health', 'ok') && Storage::disk('public')->delete('.health');
        });

        if ($request->query('scope') !== 'deploy') {
            $checks['scheduler'] = $this->scheduler();
            $checks['queue'] = $this->queue();
            $checks['backups'] = $this->backups();
        }

        $statuses = array_column($checks, 'status');
        $status = in_array('fail', $statuses, true) ? 'down' : (in_array('warn', $statuses, true) ? 'degraded' : 'ok');

        return response()->json(['status' => $status, 'checks' => $checks, 'time' => now()->toIso8601String()], $status === 'down' ? 503 : 200);
    }

    private function check(callable $test): array
    {
        try {
            return ['status' => $test() ? 'ok' : 'fail'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'fail', 'error' => class_basename($e)];
        }
    }

    private function scheduler(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        $age = $last ? now()->timestamp - $last : null;

        return ['status' => $age !== null && $age < 180 ? 'ok' : 'fail', 'last_run_seconds_ago' => $age, 'hint' => $age === null || $age >= 180 ? 'The cron job (php artisan schedule:run every minute) is not running.' : null];
    }

    private function queue(): array
    {
        try {
            $waiting = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');
            $age = $oldest ? now()->timestamp - (int) $oldest : 0;
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return ['status' => 'fail'];
        }

        return [
            'status' => $age > 900 ? 'fail' : ($failed > 0 || $age > 300 ? 'warn' : 'ok'),
            'waiting' => $waiting,
            'oldest_seconds' => $age,
            'failed_last_24h' => $failed,
        ];
    }

    private function backups(): array
    {
        try {
            $disk = Storage::disk('backups');
            $name = config('backup.backup.name');
            $latest = collect($disk->files($name))->filter(fn ($f) => str_ends_with($f, '.zip'))->map(fn ($f) => $disk->lastModified($f))->max();
        } catch (Throwable) {
            return ['status' => 'warn', 'hint' => 'Backup storage is not reachable.'];
        }

        $hours = $latest ? (int) floor((now()->timestamp - $latest) / 3600) : null;

        return ['status' => $hours !== null && $hours <= 30 ? 'ok' : 'warn', 'last_backup_hours_ago' => $hours];
    }

    private function version(): ?string
    {
        $file = base_path('RELEASE');

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }
}
