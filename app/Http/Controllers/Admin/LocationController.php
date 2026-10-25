<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationRequest;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Location::class);

        $actor = $request->user();

        $locations = Location::query()
            ->when(! $actor->isAdmin(), fn ($query) => $query->whereKey($actor->locationIds()))
            ->withCount(['users as staff_count' => fn ($query) => $query->where('is_active', true)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location) => [
                ...$location->toOption(),
                'type_label' => $location->type->label(),
                'address' => $location->address,
                'phone' => $location->phoneForDisplay(),
                'is_active' => $location->is_active,
                'staff_count' => $location->staff_count,
                'can' => [
                    'update' => $actor->can('update', $location),
                    'toggleActive' => $actor->can('toggleActive', $location),
                ],
            ]);

        return Inertia::render('Admin/Locations/Index', [
            'locations' => $locations,
            'canCreate' => $actor->can('create', Location::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Location::class);

        return Inertia::render('Admin/Locations/Form', [
            'location' => null,
            'types' => LocationType::options(),
        ]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        $location = DB::transaction(function () use ($request) {
            $location = Location::create($request->locationData());
            LocationSetting::for($location)->fill($request->settingsData())->save();

            AuditLog::record('location.created', $location, "Added location {$location->name} ({$location->code})", null, $location->only(['name', 'code', 'type']));

            return $location;
        });

        return to_route('locations.index')->with('success', "{$location->name} was added. Give staff access to it on the Staff page.");
    }

    public function edit(Location $location): Response
    {
        Gate::authorize('update', $location);

        $settings = LocationSetting::for($location);

        return Inertia::render('Admin/Locations/Form', [
            'location' => [
                ...$location->toOption(),
                'address' => $location->address,
                'phone' => $location->phoneForDisplay(),
                'is_active' => $location->is_active,
                'receipt_header' => $settings->receipt_header,
                'receipt_footer' => $settings->receipt_footer,
                'business_day_starts_at' => $settings->business_day_starts_at,
                'auto_lock_minutes' => $settings->auto_lock_minutes,
            ],
            'types' => LocationType::options(),
        ]);
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        DB::transaction(function () use ($request, $location) {
            $settings = LocationSetting::for($location);
            $before = [...$location->only(['name', 'code', 'type', 'address', 'phone']), ...$settings->only(['receipt_header', 'receipt_footer', 'business_day_starts_at', 'auto_lock_minutes'])];

            $location->fill($request->locationData())->save();
            $settings->fill($request->settingsData())->save();

            $after = [...$location->only(['name', 'code', 'type', 'address', 'phone']), ...$settings->only(['receipt_header', 'receipt_footer', 'business_day_starts_at', 'auto_lock_minutes'])];
            $before['type'] = $before['type'] instanceof LocationType ? $before['type']->value : $before['type'];
            $after['type'] = $after['type'] instanceof LocationType ? $after['type']->value : $after['type'];
            $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] != $value, ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                AuditLog::record('location.updated', $location, "Updated {$location->name} (".implode(', ', $changed).')', array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
            }
        });

        return to_route('locations.index')->with('success', "{$location->name} was saved.");
    }

    public function toggleActive(Request $request, Location $location): RedirectResponse
    {
        Gate::authorize('toggleActive', $location);

        if ($location->is_active && Location::query()->active()->count() === 1) {
            return back()->with('error', 'You need at least one active location.');
        }

        $location->update(['is_active' => ! $location->is_active]);
        AuditLog::record($location->is_active ? 'location.activated' : 'location.deactivated', $location, ($location->is_active ? 'Activated ' : 'Deactivated ').$location->name);

        $homeless = User::query()->active()->where('home_location_id', $location->id)->count();

        return back()->with('success', $location->is_active
            ? "{$location->name} is active again."
            : "{$location->name} was deactivated.".($homeless ? " {$homeless} staff have it as their home location: they'll use another of their locations, or are locked out until you move them." : ''));
    }
}
