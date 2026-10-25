<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\CurrentLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Top-bar location switcher. Only locations the user has (admins: any active one, or "all").
 */
class LocationSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['location' => ['required']]);
        $user = $request->user();

        if ($data['location'] === CurrentLocation::ALL) {
            abort_unless($user->role->seesAllLocations(), 403, 'Only admins can view all locations.');

            $request->session()->put(CurrentLocation::SESSION_KEY, CurrentLocation::ALL);

            return back()->with('success', 'Showing all locations.');
        }

        $location = filter_var($data['location'], FILTER_VALIDATE_INT) === false
            ? null
            : Location::query()->accessibleTo($user)->find((int) $data['location']);

        abort_if($location === null, 403, "You don't have access to that location.");

        $request->session()->put(CurrentLocation::SESSION_KEY, $location->id);

        return back()->with('success', "Now working at {$location->name}.");
    }
}
