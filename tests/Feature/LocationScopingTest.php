<?php

use App\Models\AuditLog;
use App\Models\Concerns\BelongsToLocation;
use App\Models\Concerns\TracksSyncVersion;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentLocation;
use App\Support\SyncVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

class ScopedThing extends Model
{
    use BelongsToLocation, TracksSyncVersion;

    protected $table = 'scoped_things';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('scoped_things', function (Blueprint $table) {
        $table->id();
        $table->foreignId('location_id');
        $table->string('name');
        $table->unsignedBigInteger('sync_version')->default(0);
        $table->timestamps();
    });

    $this->showroom = Location::factory()->create();
    $this->kandy = Location::factory()->create();
    $this->warehouse = Location::factory()->warehouse()->create();
});

test('new records take the current location and a fresh sync version', function () {
    $user = User::factory()->at($this->showroom, $this->kandy)->create();
    $this->actingAs($user);

    CurrentLocation::set($this->kandy);
    $first = ScopedThing::create(['name' => 'A']);
    $second = ScopedThing::create(['name' => 'B']);
    $first->update(['name' => 'A2']);

    expect($first->location_id)->toBe($this->kandy->id)
        ->and($second->sync_version)->toBe(2)
        ->and($first->fresh()->sync_version)->toBe(3)
        ->and(SyncVersion::current($this->kandy->id))->toBe(3)
        ->and(SyncVersion::current($this->showroom->id))->toBe(0);

    // Without a current location (queue jobs, commands) the user's home location is used.
    CurrentLocation::set(null);
    expect(ScopedThing::create(['name' => 'C'])->location_id)->toBe($this->showroom->id);
});

test('forLocation scopes to the current location, or every visible one for all locations', function () {
    foreach ([$this->showroom, $this->kandy, $this->warehouse] as $location) {
        ScopedThing::create(['name' => "at {$location->id}", 'location_id' => $location->id]);
    }

    $manager = User::factory()->manager()->at($this->showroom, $this->kandy)->create();
    $this->actingAs($manager);
    CurrentLocation::set($this->showroom);

    expect(ScopedThing::forLocation()->pluck('location_id')->all())->toBe([$this->showroom->id])
        ->and(ScopedThing::forLocation($this->warehouse)->count())->toBe(1)
        ->and(ScopedThing::visibleTo($manager)->pluck('location_id')->sort()->values()->all())->toBe([$this->showroom->id, $this->kandy->id]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    CurrentLocation::set(null, all: true);

    expect(ScopedThing::forLocation()->count())->toBe(3)
        ->and(ScopedThing::visibleTo($admin)->count())->toBe(3);
});

test('the audit log shows the current location only, unless an admin picks all', function () {
    $admin = User::factory()->admin()->at($this->warehouse)->create();
    $manager = User::factory()->manager()->at($this->showroom)->create();

    AuditLog::record('test.showroom', $this->showroom, 'Showroom thing', user: $admin);
    AuditLog::record('test.kandy', $this->kandy, 'Kandy thing', user: $admin);

    $this->actingAs($manager)->get(route('audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', 'test.showroom'));

    $this->actingAs($admin)->withSession([CurrentLocation::SESSION_KEY => 'all'])->get(route('audit.index'))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2)->where('allLocations', true));
});
