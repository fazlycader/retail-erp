<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\User;
use App\Services\Reports\OwnerDashboard;
use App\Support\BusinessDay;
use App\Support\CurrentLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everyone: a welcome with the current location and their modules. Admins, managers and
 * accountants also get the owner's view (OwnerDashboard): today across their locations.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, OwnerDashboard $owner): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->role === Role::Workshop) {
            return redirect()->route('workshop.board'); // workshop staff only work from the jobs board
        }
        if ($user->role === Role::Driver) {
            return redirect()->route('driver.index');
        }

        $location = CurrentLocation::get();
        $seesOwnerView = $user->role->canOverride() || $user->role === Role::Accountant;

        return Inertia::render('Admin/Dashboard', [
            'businessDate' => $location ? BusinessDay::current($location->id) : BusinessDay::for(now()),
            'stats' => [
                'locations' => CurrentLocation::isAll() ? Location::query()->active()->count() : null,
                'staff' => User::query()->active()
                    ->when($location, fn ($query) => $query->atLocation($location->id))
                    ->count(),
                'overdue_deliveries' => Delivery::query()->whereDate('scheduled_date', '<', today())
                    ->whereIn('status', ['scheduled', 'assigned', 'loaded', 'out_for_delivery'])
                    ->when($location, fn ($query) => $query->where('location_id', $location->id), fn ($query) => $query->whereIn('location_id', Location::accessibleTo($user)->pluck('locations.id')))
                    ->count(),
            ],
            'owner' => $seesOwnerView ? $owner->build($user) : null,
        ]);
    }
}
