<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two-factor login for staff (required for admins and accountants): set up (scan QR, confirm a code), recovery codes,
 * turn off, and the challenge after the password at login.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $pending = $request->session()->get('2fa.pending');

        return Inertia::render('Profile/TwoFactor', [
            'enabled' => $user->hasTwoFactor(),
            'required' => TwoFactor::required($user),
            'pending' => $pending ? ['qr' => TwoFactor::qrSvg($user, $pending), 'secret' => trim(chunk_split($pending, 4, ' '))] : null,
            'recoveryCodes' => $request->session()->get('2fa.codes'),
            'remaining' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->session()->put('2fa.pending', TwoFactor::newSecret());

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = $request->session()->get('2fa.pending');
        $code = (string) $request->validate(['code' => ['required', 'string']])['code'];

        if (! $secret || ! TwoFactor::verify($user, $secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }

        [$plain, $hashes] = TwoFactor::recoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('2fa.pending');
        AuditLog::record('user.2fa_enabled', $user, "{$user->name} turned on two-factor login", null, null, $user);

        return back()->with('2fa.codes', $plain)->with('success', 'Two-factor login is on. Save your recovery codes now.');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 403);
        $request->validate(['password' => ['required', 'current_password']]);

        [$plain, $hashes] = TwoFactor::recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();

        return back()->with('2fa.codes', $plain)->with('success', 'New recovery codes made. The old ones no longer work.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'current_password']]);

        if (TwoFactor::required($user)) {
            return back()->with('error', 'Two-factor login is required for your role.');
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        AuditLog::record('user.2fa_disabled', $user, "{$user->name} turned off two-factor login", null, null, $user);

        return back()->with('success', 'Two-factor login is off.');
    }

    // ---- Login challenge ---------------------------------------------------------------------

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request) ?? throw ValidationException::withMessages(['code' => 'Your sign-in expired. Enter your password again.']);
        $data = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:20']]);
        $key = "2fa-login:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait '.RateLimiter::availableIn($key).' seconds.']);
        }

        $ok = filled($data['recovery_code'] ?? null)
            ? TwoFactor::useRecoveryCode($user, $data['recovery_code'])
            : TwoFactor::verify($user, $user->two_factor_secret, (string) ($data['code'] ?? ''));

        if (! $ok) {
            RateLimiter::hit($key, 60);
            AuditLog::record('user.2fa_failed', $user, "Wrong two-factor code for {$user->name}", null, null, null);

            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        RateLimiter::clear($key);
        $remember = $request->session()->pull('login.2fa')['remember'] ?? false;
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        if (filled($data['recovery_code'] ?? null)) {
            AuditLog::record('user.2fa_recovery_used', $user, "{$user->name} signed in with a recovery code", null, null, $user);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('login.2fa');

        // The password step is valid for 10 minutes.
        if (! $pending || now()->timestamp - ($pending['at'] ?? 0) > 600) {
            return null;
        }

        return User::query()->where('is_active', true)->find($pending['id']);
    }
}
