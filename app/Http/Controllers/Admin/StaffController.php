<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\User;
use App\Rules\SriLankanPhone;
use App\Support\CurrentLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff at the current location (admins: also "All locations"). Admins manage everyone;
 * managers manage non-admin staff based at their own locations (UserPolicy).
 */
class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $actor = $request->user();

        $staff = User::query()
            ->with(['homeLocation:id,name,code', 'locations:id,code'])
            ->when(! CurrentLocation::isAll(), fn ($query) => $query->atLocation(CurrentLocation::id()))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', '%'.(SriLankanPhone::normalize($search) ?? $search).'%')))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => SriLankanPhone::display($user->phone),
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'home_location' => $user->homeLocation?->name,
                'other_locations' => $user->locations->where('id', '!=', $user->home_location_id)->pluck('code')->values(),
                'is_active' => $user->is_active,
                'has_pin' => $user->hasPin(),
                'pin_locked' => $user->pinLocked(),
                'last_login_at' => $user->last_login_at?->format('j M Y, g:i a'),
                'can' => [
                    'update' => $actor->can('update', $user),
                    'toggleActive' => $actor->can('toggleActive', $user),
                ],
            ]);

        return Inertia::render('Admin/Staff/Index', [
            'staff' => $staff,
            'filters' => $filters,
            'roles' => Role::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Staff/Form', [
            'staff' => null,
            'roles' => Role::options($request->user()->role->assignableRoles()),
            'locations' => self::locationOptions($request->user()),
            'defaultLocationId' => CurrentLocation::id(),
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = new User([
                ...collect($data)->except(['home_location_id', 'location_ids'])->all(),
                'phone' => self::phone($data['phone'] ?? null),
                'pin' => $data['pin'] ?? null,
            ]);
            $user->email_verified_at = now();
            $user->save();
            $user->assignLocations($data['home_location_id'] ?? null, $data['location_ids'] ?? []);

            AuditLog::record('user.created', $user, "Added {$user->name} ({$user->role->label()})", null, self::snapshot($user));

            return $user;
        });

        return to_route('staff.index')->with('success', "{$user->name} was added.");
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Admin/Staff/Form', [
            'staff' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => SriLankanPhone::display($user->phone),
                'role' => $user->role->value,
                'is_active' => $user->is_active,
                'has_pin' => $user->hasPin(),
                'pin_locked' => $user->pinLocked(),
                'is_self' => $request->user()->is($user),
                'home_location_id' => $user->home_location_id,
                'location_ids' => array_values(array_diff($user->locationIds(), [$user->home_location_id])),
            ],
            'roles' => Role::options($request->user()->role->assignableRoles()),
            'locations' => self::locationOptions($request->user()),
            'defaultLocationId' => null,
        ]);
    }

    public function update(UpdateStaffRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $before = self::snapshot($user);

        DB::transaction(function () use ($request, $user, $data, $before) {
            $user->fill(collect($data)->except(['password', 'pin', 'remove_pin', 'home_location_id', 'location_ids'])->all());
            $user->phone = self::phone($data['phone'] ?? null);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            if (! empty($data['pin'])) {
                $user->pin = $data['pin'];
            } elseif ($request->boolean('remove_pin')) {
                $user->pin = null;
            }

            $user->save();

            // A manager can't see or change links to locations they don't run: keep those.
            $manageable = StoreStaffRequest::assignableLocationIds($request->user());
            $keep = array_values(array_diff($user->locationIds(), $manageable));
            $user->assignLocations($data['home_location_id'] ?? null, $data['location_ids'] ?? [], $keep);

            $after = self::snapshot($user);
            $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

            if ($changed !== [] || ! empty($data['password']) || ! empty($data['pin']) || $request->boolean('remove_pin')) {
                AuditLog::record(
                    in_array('role', $changed, true) ? 'user.role_changed' : 'user.updated',
                    $user,
                    "Updated {$user->name}".($changed ? ' ('.implode(', ', $changed).')' : '')
                        .(! empty($data['password']) ? ', new password' : '')
                        .(! empty($data['pin']) ? ', new PIN' : ($request->boolean('remove_pin') ? ', PIN removed' : '')),
                    array_intersect_key($before, array_flip($changed)) ?: null,
                    array_intersect_key($after, array_flip($changed)) ?: null,
                );
            }
        });

        return to_route('staff.index')->with('success', "{$user->name} was updated.");
    }

    public function unlockPin(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $user->unlockPin();
        AuditLog::record('user.pin_unlocked', $user, "{$request->user()->name} unlocked {$user->name}'s PIN");

        return back()->with('success', "{$user->name}'s PIN is unlocked.");
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('toggleActive', $user);

        $user->update(['is_active' => ! $user->is_active]);
        AuditLog::record($user->is_active ? 'user.activated' : 'user.deactivated', $user, ($user->is_active ? 'Activated ' : 'Deactivated ').$user->name);

        return back()->with('success', $user->is_active
            ? "{$user->name} was activated."
            : "{$user->name} was deactivated and can no longer sign in.");
    }

    /**
     * @return array<int, array{id: int, name: string, code: string, type: string}>
     */
    private static function locationOptions(User $actor): array
    {
        return Location::query()->accessibleTo($actor)->orderBy('name')->get()->map->toOption()->all();
    }

    private static function phone(?string $phone): ?string
    {
        return blank($phone) ? null : SriLankanPhone::normalize($phone);
    }

    /**
     * @return array{role: string, is_active: bool, home_location_id: ?int, location_ids: array<int, int>, email: string, name: string}
     */
    private static function snapshot(User $user): array
    {
        $ids = $user->locationIds();
        sort($ids);

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            'home_location_id' => $user->home_location_id,
            'location_ids' => $ids,
        ];
    }
}
