<?php

use App\Exceptions\ApprovalRefused;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentLocation;
use App\Support\ManagerApproval;
use App\Support\Terminal;

beforeEach(function () {
    $this->showroom = Location::factory()->create();
    $this->kandy = Location::factory()->create();
});

test('ten wrong PINs lock the PIN for 15 minutes', function () {
    $user = User::factory()->withPin('1234')->at($this->showroom)->create();

    foreach (range(1, 9) as $try) {
        expect($user->attemptPin('0000'))->toBeFalse();
    }

    expect($user->fresh()->pinLocked())->toBeFalse();
    expect($user->attemptPin('0000'))->toBeFalse()
        ->and($user->fresh()->pinLocked())->toBeTrue()
        // Even the right PIN fails while locked.
        ->and($user->fresh()->attemptPin('1234'))->toBeFalse()
        ->and(AuditLog::where('action', 'user.pin_locked')->where('subject_id', $user->id)->exists())->toBeTrue();

    $this->travel(16)->minutes();

    expect($user->fresh()->attemptPin('1234'))->toBeTrue()
        ->and($user->fresh()->pin_failed_attempts)->toBe(0);
});

test('failures older than the window do not count', function () {
    $user = User::factory()->withPin('1234')->create();

    foreach (range(1, 9) as $try) {
        $user->attemptPin('0000');
    }

    $this->travel(16)->minutes();
    $user->attemptPin('0000');

    expect($user->fresh())->pinLocked()->toBeFalse()->pin_failed_attempts->toBe(1);
});

test('a manager can unlock a locked PIN', function () {
    $manager = User::factory()->manager()->at($this->showroom)->create();
    $cashier = User::factory()->withPin()->at($this->showroom)->create(['pin_locked_until' => now()->addMinutes(10)]);

    $this->actingAs($manager)->patch(route('staff.unlock-pin', $cashier))->assertSessionHas('success');

    expect($cashier->fresh()->pinLocked())->toBeFalse()
        ->and(AuditLog::where('action', 'user.pin_unlocked')->exists())->toBeTrue();
});

test('the terminal lock screen lists only staff of its location', function () {
    $here = User::factory()->withPin()->at($this->showroom)->create(['name' => 'Here']);
    User::factory()->withPin()->at($this->kandy)->create(['name' => 'Elsewhere']);
    User::factory()->at($this->showroom)->create(['name' => 'No PIN']);

    $this->withCookie(Terminal::COOKIE, json_encode(['id' => 'abc', 'location_id' => $this->showroom->id, 'enabled_by' => 1]))
        ->get(route('terminal.show'))
        ->assertInertia(fn ($page) => $page
            ->component('Terminal/Lock')
            ->where('staff', [['id' => $here->id, 'name' => 'Here', 'role' => 'Cashier']]));
});

test('the terminal signs staff in with their PIN at the terminal location', function () {
    $cashier = User::factory()->withPin('4321')->at($this->kandy, $this->showroom)->create();
    $cookie = json_encode(['id' => 'abc', 'location_id' => $this->showroom->id, 'enabled_by' => 1]);

    $this->withCookie(Terminal::COOKIE, $cookie)
        ->post(route('terminal.unlock'), ['user_id' => $cashier->id, 'pin' => '4321'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($cashier);
    expect(session(CurrentLocation::SESSION_KEY))->toBe($this->showroom->id);
});

test('the terminal refuses staff of other locations, wrong PINs and non-terminal devices', function () {
    $elsewhere = User::factory()->withPin('4321')->at($this->kandy)->create();
    $here = User::factory()->withPin('4321')->at($this->showroom)->create();
    $cookie = json_encode(['id' => 'abc', 'location_id' => $this->showroom->id, 'enabled_by' => 1]);

    $this->withCookie(Terminal::COOKIE, $cookie)
        ->post(route('terminal.unlock'), ['user_id' => $elsewhere->id, 'pin' => '4321'])
        ->assertSessionHasErrors('pin');

    $this->withCookie(Terminal::COOKIE, $cookie)
        ->post(route('terminal.unlock'), ['user_id' => $here->id, 'pin' => '0000'])
        ->assertSessionHasErrors('pin');

    $this->defaultCookies = []; // an ordinary device
    $this->post(route('terminal.unlock'), ['user_id' => $here->id, 'pin' => '4321'])->assertForbidden();

    $this->assertGuest();
});

test('a manager PIN approves an action at their location', function () {
    $cashier = User::factory()->at($this->showroom)->create();
    $manager = User::factory()->manager()->withPin('9999')->at($this->showroom)->create();
    $otherManager = User::factory()->manager()->withPin('9999')->at($this->kandy)->create();
    $admin = User::factory()->admin()->withPin('5555')->at($this->kandy)->create();

    expect(ManagerApproval::verify($cashier, $this->showroom->id, $manager->id, '9999', 'test.approve', null, 'a test')->is($manager))->toBeTrue()
        ->and(ManagerApproval::verify($cashier, $this->showroom->id, $admin->id, '5555', 'test.approve', null, 'a test')->is($admin))->toBeTrue()
        ->and(collect(ManagerApproval::approvers($this->showroom->id))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$manager->id, $admin->id])->sort()->values()->all());

    expect(fn () => ManagerApproval::verify($cashier, $this->showroom->id, $otherManager->id, '9999', 'test.approve', null, 'a test'))
        ->toThrow(ApprovalRefused::class);
    expect(fn () => ManagerApproval::verify($cashier, $this->showroom->id, $manager->id, '0000', 'test.approve', null, 'a test'))
        ->toThrow(ApprovalRefused::class);

    expect(AuditLog::where('action', 'test.approve_denied')->count())->toBe(2);
});
