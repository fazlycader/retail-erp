<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\LocationSetting;
use App\Support\Brand;
use App\Support\CurrentLocation;
use App\Support\Navigation;
use App\Support\Terminal;
use App\Support\UploadLimit;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                ] : null,
                'can' => $user ? [
                    'manageStaff' => $user->can('manage-staff'),
                    'manageSettings' => $user->can('manage-settings'),
                    'manageLocations' => $user->can('manage-locations'),
                    'override' => $user->can('override'),
                    'viewAuditLog' => $user->can('view-audit-log'),
                    'editCatalog' => $user->can('edit-catalog'),
                    'viewCost' => $user->can('view-cost'),
                    'viewJobCosts' => $user->can('view-job-costs'),
                    'manageWorkshop' => $user->can('manage-workshop'),
                ] : [],
            ],
            'navigation' => fn () => $user ? Navigation::for($user) : [],
            'company' => function () {
                $company = CompanySetting::current();

                return [
                    'name' => $company->name,
                    'logo_url' => $company->logoUrl(),
                    'currency' => config('app.currency'),
                ];
            },
            // Top-bar switcher: the current location (null = "All locations") and the ones the user can pick.
            'location' => fn () => CurrentLocation::get()?->toOption(),
            'locations' => fn () => $user ? CurrentLocation::accessible()->map(fn (Location $l) => $l->toOption())->values() : [],
            'locationScope' => fn () => $user ? [
                'all' => CurrentLocation::isAll(),
                'can_all' => $user->role->seesAllLocations() && ResolveCurrentLocation::allowsAll($request),
            ] : null,
            'uploadLimit' => UploadLimit::bytes(),
            // MVRK+ (the system's maker): credit lines and the support WhatsApp link.
            'brand' => Brand::toArray(),
            // Shared terminal: PIN lock screen + auto-lock after inactivity.
            'terminal' => fn () => ($terminal = Terminal::fromRequest($request)) && $user
                ? ['enabled' => true, 'auto_lock_minutes' => LocationSetting::for($terminal['location_id'])->auto_lock_minutes]
                : ['enabled' => false],
            'isTerminalDevice' => fn () => Terminal::fromRequest($request) !== null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
