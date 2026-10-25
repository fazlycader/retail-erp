<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CurrentLocation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('view-audit-log');

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'user' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        $scoped = fn () => AuditLog::query()->forLocation();

        $logs = $scoped()
            ->with(['user:id,name', 'location:id,code'])
            ->when($filters['action'] ?? null, fn ($q, $action) => str_ends_with($action, '.*') ? $q->where('action', 'like', substr($action, 0, -1).'%') : $q->where('action', $action))
            ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            ->when($filters['date'] ?? null, function ($q, $date) {
                $start = CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();
                $q->whereBetween('created_at', [$start->utc(), $start->endOfDay()->utc()]);
            })
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('summary', 'like', "%{$search}%"))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'summary' => $log->summary,
                'user' => $log->user?->name ?? 'System',
                'location' => $log->location?->code,
                'subject' => $log->subject_type ? "{$log->subject_type} #{$log->subject_id}" : null,
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
                'at' => $log->created_at->setTimezone(config('app.timezone'))->format('j M Y, g:i:s a'),
                'is_override' => str_ends_with($log->action, '_denied') || $log->action === 'user.pin_locked',
            ]);

        return Inertia::render('Admin/Audit/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => $scoped()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::query()->whereIn('id', $scoped()->select('user_id'))->orderBy('name')->get(['id', 'name']),
            'allLocations' => CurrentLocation::isAll(),
        ]);
    }
}
