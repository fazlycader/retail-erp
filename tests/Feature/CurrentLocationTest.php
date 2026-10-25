<?php

use App\Models\Location;
use App\Models\User;
use App\Support\CurrentLocation;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->warehouse = Location::factory()->warehouse()->create(['name' => 'Head Office Warehouse', 'code' => 'HO']);
    $this->showroom = Location::factory()->create(['name' => 'Main Showroom', 'code' => 'MS']);
    $this->kandy = Location::factory()->create(['name' => 'Kandy Showroom', 'code' => 'KDY']);
});

test('staff start at their home location and see only their locations', function () {
    $user = User::factory()->at($this->showroom, $this->kandy)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('location.id', $this->showroom->id)
            ->where('locations', fn ($locations) => collect($locations)->pluck('code')->sort()->values()->all() === ['KDY', 'MS'])
            ->where('locationScope.can_all', false));
});

test('staff can switch to one of their locations', function () {
    $user = User::factory()->at($this->showroom, $this->kandy)->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('location.switch'), ['location' => $this->kandy->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(CurrentLocation::SESSION_KEY, $this->kandy->id);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('location.code', 'KDY'));
});

test('the switcher rejects locations the user does not have', function () {
    $user = User::factory()->at($this->showroom)->create();

    $this->actingAs($user)
        ->post(route('location.switch'), ['location' => $this->warehouse->id])
        ->assertForbidden();

    $this->actingAs($user)->post(route('location.switch'), ['location' => 999])->assertForbidden();
    $this->actingAs($user)->post(route('location.switch'), ['location' => 'nonsense'])->assertForbidden();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('location.code', 'MS'));
});

test('only admins can choose all locations', function () {
    $manager = User::factory()->manager()->at($this->showroom, $this->kandy)->create();

    $this->actingAs($manager)->post(route('location.switch'), ['location' => 'all'])->assertForbidden();

    $admin = User::factory()->admin()->at($this->warehouse)->create();

    $this->actingAs($admin)->post(route('location.switch'), ['location' => 'all'])
        ->assertSessionHas(CurrentLocation::SESSION_KEY, 'all');
});

test('admins can switch to any active location', function () {
    $admin = User::factory()->admin()->at($this->warehouse)->create();

    $this->actingAs($admin)->post(route('location.switch'), ['location' => $this->kandy->id])
        ->assertSessionHas(CurrentLocation::SESSION_KEY, $this->kandy->id);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('location.code', 'KDY')
        ->has('locations', 3));
});

test('all locations only applies on pages that allow it', function () {
    $admin = User::factory()->admin()->at($this->warehouse)->create();

    $this->actingAs($admin)
        ->withSession([CurrentLocation::SESSION_KEY => 'all'])
        ->get(route('staff.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('location', null)
            ->where('locationScope', ['all' => true, 'can_all' => true]));

    // POS works at one location: it falls back to the home location for this page...
    $this->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
        ->where('location.code', 'HO')
        ->where('locationScope', ['all' => false, 'can_all' => false]));

    // ...and keeps the choice for the next page that allows it.
    $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->where('locationScope.all', true));
});

test('a stale or removed location falls back to the home location', function () {
    $user = User::factory()->at($this->showroom, $this->kandy)->create();

    $this->actingAs($user)->withSession([CurrentLocation::SESSION_KEY => $this->kandy->id])
        ->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('location.code', 'KDY'));

    // Access to Kandy removed while signed in.
    $user->assignLocations($this->showroom->id);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('location.code', 'MS'));
    $this->assertSame($this->showroom->id, session(CurrentLocation::SESSION_KEY));

    // A location someone else's session pointed at.
    $this->withSession([CurrentLocation::SESSION_KEY => $this->warehouse->id])
        ->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('location.code', 'MS'));
});

test('a deactivated location is skipped', function () {
    $user = User::factory()->at($this->showroom, $this->kandy)->create();
    $this->showroom->update(['is_active' => false]);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('location.code', 'KDY')
        ->has('locations', 1));

    $this->post(route('location.switch'), ['location' => $this->showroom->id])->assertForbidden();
});

test('staff without any active location are blocked', function () {
    $user = User::factory()->at($this->showroom)->create();
    $this->showroom->update(['is_active' => false]);

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    $this->post(route('logout'))->assertRedirect('/');
});
