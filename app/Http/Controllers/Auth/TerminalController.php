<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentLocation;
use App\Support\Terminal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared terminals: lock screen, PIN switch, and turning terminal mode on/off for a device.
 * A terminal belongs to one location; only staff who work there can switch on it.
 */
class TerminalController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $terminal = Terminal::fromRequest($request);

        if ($terminal === null) {
            return redirect()->route('login')->with('status', 'This device is not set up as a shared terminal. Sign in with your email and password.');
        }

        $location = Location::query()->findOrFail($terminal['location_id']);
        $company = CompanySetting::current();

        $staff = User::query()
            ->active()
            ->whereNotNull('pin')
            ->where(fn ($query) => $query->where('role', 'admin')->orWhere(fn ($q) => $q->atLocation($location->id)))
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->label()]);

        return Inertia::render('Terminal/Lock', [
            'company' => ['name' => $company->name, 'logo_url' => $company->logoUrl()],
            'location' => $location->name,
            'staff' => $staff,
            'current' => $request->user()?->only(['id', 'name']),
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $terminal = Terminal::fromRequest($request);
        abort_if($terminal === null, 403, 'This device is not a shared terminal.');

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ]);

        $user = User::query()->find($data['user_id']);
        $user = $user?->worksAt($terminal['location_id']) ? $user : null;

        if ($user?->pinLocked()) {
            throw ValidationException::withMessages(['pin' => 'This PIN is locked for a few minutes after too many wrong tries. Ask a manager to unlock it.']);
        }

        if ($user === null || ! $user->attemptPin($data['pin'], 'terminal')) {
            throw ValidationException::withMessages(['pin' => $user?->pinLocked() ? 'Too many wrong tries: this PIN is now locked for '.User::PIN_LOCK_MINUTES.' minutes. Ask a manager to unlock it.' : 'That PIN is not right. Try again.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['pin' => 'This account is inactive. Ask a manager.']);
        }

        if ($request->user() && $request->user()->isNot($user)) {
            Auth::guard('web')->logout();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(CurrentLocation::SESSION_KEY, $terminal['location_id']);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Lock the screen: sign the current person out but keep the device's terminal marker.
     */
    public function lock(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('terminal.show');
    }

    public function enable(Request $request): RedirectResponse
    {
        Gate::authorize('override');

        $location = CurrentLocation::get();

        if ($location === null) {
            return back()->with('error', 'Pick the location this device is at first (top bar), then try again.');
        }

        $user = $request->user();
        AuditLog::record('terminal.enabled', $location, "{$user->name} set up a shared terminal at {$location->name}", null, ['user_agent' => substr((string) $request->userAgent(), 0, 120)], $user);

        return back()->with('success', "This device is now a shared terminal for {$location->name}. Staff can switch with their PIN.")
            ->withCookie(Terminal::enableCookie($location->id, $user->id));
    }

    public function disable(Request $request): RedirectResponse
    {
        Gate::authorize('override');

        return back()->with('success', 'This device is no longer a shared terminal.')->withCookie(Terminal::forgetCookie());
    }
}
