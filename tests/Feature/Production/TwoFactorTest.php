<?php

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\CompanySettingSeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed([LocationSeeder::class, CompanySettingSeeder::class]);
    $this->manager = User::factory()->manager()->create(['email' => 'mala@lk.test', 'password' => 'Secret-pass-123']);
});

function enableTwoFactor(User $user): array
{
    test()->actingAs($user)->post(route('two-factor.start'), ['password' => 'Secret-pass-123'])->assertRedirect();
    $secret = session('2fa.pending');
    test()->actingAs($user)->post(route('two-factor.confirm'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasNoErrors();

    return [$secret, session('2fa.codes')];
}

test('set up: password, QR, confirm with a code; recovery codes shown once and stored hashed', function () {
    $this->actingAs($this->manager)->post(route('two-factor.start'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->actingAs($this->manager)->post(route('two-factor.start'), ['password' => 'Secret-pass-123']);
    $this->actingAs($this->manager)->get(route('two-factor.show'))->assertInertia(fn ($page) => $page->component('Profile/TwoFactor')->where('pending.qr', fn ($svg) => str_contains($svg, '<svg')));

    $this->actingAs($this->manager)->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');

    Cache::flush();
    [$secret, $codes] = enableTwoFactor($this->manager->fresh());
    $user = $this->manager->fresh();

    expect($user->hasTwoFactor())->toBeTrue()
        ->and($user->two_factor_secret)->toBe($secret)
        ->and($user->getRawOriginal('two_factor_secret'))->not->toContain($secret) // encrypted at rest
        ->and($codes)->toHaveCount(8)
        ->and(collect($user->two_factor_recovery_codes)->contains($codes[0]))->toBeFalse() // hashed
        ->and(AuditLog::query()->where('action', 'user.2fa_enabled')->count())->toBe(1);
});

test('login asks for the code after the password; wrong codes fail; recovery codes work once', function () {
    [$secret, $codes] = enableTwoFactor($this->manager);
    auth()->logout();
    Cache::flush();

    $this->post(route('login'), ['email' => 'mala@lk.test', 'password' => 'Secret-pass-123'])->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->post(route('two-factor.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post(route('two-factor.verify'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->manager);

    // The same code can't be used twice (replay).
    auth()->logout();
    $this->post(route('login'), ['email' => 'mala@lk.test', 'password' => 'Secret-pass-123']);
    $this->post(route('two-factor.verify'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasErrors('code');

    // A recovery code instead, once.
    $this->post(route('two-factor.verify'), ['recovery_code' => $codes[0]])->assertRedirect(route('dashboard'));
    auth()->logout();
    $this->post(route('login'), ['email' => 'mala@lk.test', 'password' => 'Secret-pass-123']);
    $this->post(route('two-factor.verify'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
    expect(count($this->manager->fresh()->two_factor_recovery_codes))->toBe(7);
});

test('the challenge expires and is rate limited', function () {
    enableTwoFactor($this->manager);
    auth()->logout();

    $this->post(route('login'), ['email' => 'mala@lk.test', 'password' => 'Secret-pass-123']);
    foreach (range(1, 5) as $i) {
        $this->post(route('two-factor.verify'), ['code' => '000000']);
    }
    $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    expect(session('errors')->first('code'))->toContain('Too many tries');

    $this->travel(11)->minutes();
    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
});

test('admins and accountants must set it up first; managers and cashiers may choose', function () {
    config(['security.require_two_factor' => true]);
    $admin = User::factory()->admin()->create(['password' => 'Secret-pass-123']);
    $accountant = User::factory()->accountant()->create(['password' => 'Secret-pass-123']);

    foreach ([$admin, $accountant] as $user) {
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('two-factor.show'));
        $this->actingAs($user)->getJson(route('reports.exports'))->assertForbidden();
        $this->actingAs($user)->get(route('two-factor.show'))->assertOk();
    }

    $this->actingAs($this->manager)->get(route('dashboard'))->assertOk();
    $this->actingAs(User::factory()->cashier()->create())->get(route('dashboard'))->assertOk();

    enableTwoFactor($admin);
    $this->actingAs($admin->fresh())->get(route('dashboard'))->assertOk();

    // And it can't be switched off while required.
    $this->actingAs($admin->fresh())->delete(route('two-factor.disable'), ['password' => 'Secret-pass-123'])->assertSessionHas('error');
    expect($admin->fresh()->hasTwoFactor())->toBeTrue();

    // A manager who chose it can switch it off.
    enableTwoFactor($this->manager);
    $this->actingAs($this->manager->fresh())->delete(route('two-factor.disable'), ['password' => 'Secret-pass-123'])->assertSessionHasNoErrors();
    expect($this->manager->fresh()->hasTwoFactor())->toBeFalse();
});
