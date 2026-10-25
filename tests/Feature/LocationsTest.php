<?php

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationSetting;
use App\Models\User;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;

function locationPayload(array $overrides = []): array
{
    return [
        'name' => 'Galle Showroom',
        'code' => 'gle',
        'type' => 'showroom',
        'address' => '12 Main Street, Galle',
        'phone' => '091 222 3333',
        'receipt_header' => 'Welcome',
        'receipt_footer' => 'Exchanges within 7 days',
        'business_day_starts_at' => '02:00',
        'auto_lock_minutes' => 10,
        ...$overrides,
    ];
}

test('an admin can create a location with its settings', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('locations.store'), locationPayload())
        ->assertRedirect(route('locations.index'))
        ->assertSessionHasNoErrors();

    $location = Location::where('code', 'GLE')->firstOrFail();
    $settings = LocationSetting::for($location);

    expect($location->phone)->toBe('+94912223333')
        ->and($location->is_active)->toBeTrue()
        ->and($settings->receipt_footer)->toBe('Exchanges within 7 days')
        ->and($settings->business_day_starts_at)->toBe('02:00')
        ->and($settings->auto_lock_minutes)->toBe(10)
        ->and(AuditLog::where('action', 'location.created')->exists())->toBeTrue();

    // New locations show up in the admin's switcher straight away.
    $this->post(route('location.switch'), ['location' => $location->id])->assertSessionHasNoErrors()->assertRedirect();
});

test('location codes are unique', function () {
    Location::factory()->create(['code' => 'GLE']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('locations.store'), locationPayload())
        ->assertSessionHasErrors('code');
});

test('the business day must start by 06:00', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('locations.store'), locationPayload(['business_day_starts_at' => '09:00']))
        ->assertSessionHasErrors('business_day_starts_at');
});

test('managers edit their own locations but cannot add or deactivate any', function () {
    $mine = Location::factory()->create();
    $other = Location::factory()->create();
    $manager = User::factory()->manager()->at($mine)->create();

    $this->actingAs($manager)->get(route('locations.create'))->assertForbidden();
    $this->actingAs($manager)->post(route('locations.store'), locationPayload())->assertForbidden();
    $this->actingAs($manager)->get(route('locations.edit', $other))->assertForbidden();
    $this->actingAs($manager)->put(route('locations.update', $other), locationPayload(['code' => $other->code]))->assertForbidden();
    $this->actingAs($manager)->patch(route('locations.toggle-active', $mine))->assertForbidden();

    $this->actingAs($manager)->put(route('locations.update', $mine), locationPayload(['code' => $mine->code, 'name' => 'Renamed']))
        ->assertSessionHasNoErrors();

    expect($mine->fresh()->name)->toBe('Renamed')
        ->and(AuditLog::where('action', 'location.updated')->where('subject_id', $mine->id)->exists())->toBeTrue();
});

test('the last active location cannot be deactivated', function () {
    $only = Location::factory()->create();
    $admin = User::factory()->admin()->at($only)->create();

    $this->actingAs($admin)->patch(route('locations.toggle-active', $only))->assertSessionHas('error');

    expect($only->fresh()->is_active)->toBeTrue();
});

test('the business day follows the location cutoff', function () {
    $location = Location::factory()->create();
    LocationSetting::for($location)->fill(['business_day_starts_at' => '02:00'])->save();

    $lateNight = CarbonImmutable::parse('2026-10-24 01:30', 'Asia/Colombo');
    $morning = CarbonImmutable::parse('2026-10-24 02:00', 'Asia/Colombo');

    expect(BusinessDay::current($location->id, $lateNight))->toBe('2026-10-23')
        ->and(BusinessDay::current($location->id, $morning))->toBe('2026-10-24')
        ->and(BusinessDay::for($lateNight->utc()))->toBe('2026-10-24');

    [$start, $end] = BusinessDay::window('2026-10-23', '02:00');
    expect($start->toIso8601String())->toBe('2026-10-22T20:30:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-10-23T20:30:00+00:00');
});
