<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentLocation;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->warehouse = Location::factory()->warehouse()->create(['code' => 'HO']);
    $this->showroom = Location::factory()->create(['code' => 'MS']);
    $this->kandy = Location::factory()->create(['code' => 'KDY']);
});

function staffPayload(array $overrides = []): array
{
    return [
        'name' => 'New Cashier',
        'email' => 'new.cashier@example.com',
        'phone' => '077 123 4567',
        'role' => 'cashier',
        'is_active' => true,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        'pin' => '4321',
        'home_location_id' => null,
        'location_ids' => [],
        ...$overrides,
    ];
}

test('staff pages are only available to admins and managers', function (Role $role, int $status) {
    $user = User::factory()->role($role)->create();

    $this->actingAs($user)->get(route('staff.index'))->assertStatus($status);
    $this->actingAs($user)->get(route('staff.create'))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'manager' => [Role::Manager, 200],
    'cashier' => [Role::Cashier, 403],
    'storekeeper' => [Role::Storekeeper, 403],
    'accountant' => [Role::Accountant, 403],
]);

test('an admin can create staff with locations and a hashed PIN', function () {
    $admin = User::factory()->admin()->at($this->warehouse)->create();

    $this->actingAs($admin)
        ->post(route('staff.store'), staffPayload(['home_location_id' => $this->showroom->id, 'location_ids' => [$this->kandy->id]]))
        ->assertRedirect(route('staff.index'))
        ->assertSessionHasNoErrors();

    $staff = User::where('email', 'new.cashier@example.com')->firstOrFail();

    expect($staff->role)->toBe(Role::Cashier)
        ->and($staff->home_location_id)->toBe($this->showroom->id)
        ->and($staff->locationIds())->toEqualCanonicalizing([$this->showroom->id, $this->kandy->id])
        ->and($staff->phone)->toBe('+94771234567')
        ->and($staff->pin)->not->toBe('4321')
        ->and($staff->checkPin('4321'))->toBeTrue()
        ->and(Hash::check('secret-password', $staff->password))->toBeTrue()
        ->and(AuditLog::where('action', 'user.created')->where('subject_id', $staff->id)->exists())->toBeTrue();
});

test('non-admin staff need a home location; admins do not', function () {
    $admin = User::factory()->admin()->at($this->warehouse)->create();

    $this->actingAs($admin)->post(route('staff.store'), staffPayload())->assertSessionHasErrors('home_location_id');

    $this->actingAs($admin)->post(route('staff.store'), staffPayload(['role' => 'admin', 'email' => 'second.admin@example.com']))
        ->assertSessionHasNoErrors();
});

test('the PIN must be 4 to 6 digits', function (string $pin) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('staff.store'), staffPayload(['pin' => $pin, 'home_location_id' => $this->showroom->id]))
        ->assertSessionHasErrors('pin');
})->with(['123', '1234567', 'abcd']);

test('phone numbers must be Sri Lankan', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('staff.store'), staffPayload(['phone' => '12345', 'home_location_id' => $this->showroom->id]))
        ->assertSessionHasErrors('phone');
});

test('a manager can add staff only at their own locations and never admins', function () {
    $manager = User::factory()->manager()->at($this->showroom, $this->kandy)->create();

    $this->actingAs($manager)
        ->post(route('staff.store'), staffPayload(['home_location_id' => $this->kandy->id, 'location_ids' => [$this->showroom->id]]))
        ->assertSessionHasNoErrors();

    $this->actingAs($manager)
        ->post(route('staff.store'), staffPayload(['email' => 'a@example.com', 'home_location_id' => $this->warehouse->id]))
        ->assertSessionHasErrors('home_location_id');

    $this->actingAs($manager)
        ->post(route('staff.store'), staffPayload(['email' => 'b@example.com', 'home_location_id' => $this->showroom->id, 'location_ids' => [$this->warehouse->id]]))
        ->assertSessionHasErrors('location_ids.0');

    $this->actingAs($manager)
        ->post(route('staff.store'), staffPayload(['email' => 'c@example.com', 'role' => 'admin', 'home_location_id' => $this->showroom->id]))
        ->assertSessionHasErrors('role');

    expect(User::whereIn('email', ['a@example.com', 'b@example.com', 'c@example.com'])->count())->toBe(0);
});

test("a manager can't open another location's staff", function () {
    $manager = User::factory()->manager()->at($this->showroom)->create();
    $warehouseStaff = User::factory()->role(Role::Storekeeper)->at($this->warehouse)->create();
    $showroomStaff = User::factory()->at($this->showroom)->create();

    $this->actingAs($manager)->get(route('staff.edit', $warehouseStaff))->assertForbidden();
    $this->actingAs($manager)->put(route('staff.update', $warehouseStaff), staffPayload(['email' => $warehouseStaff->email, 'home_location_id' => $this->showroom->id]))->assertForbidden();
    $this->actingAs($manager)->patch(route('staff.toggle-active', $warehouseStaff))->assertForbidden();
    $this->actingAs($manager)->patch(route('staff.unlock-pin', $warehouseStaff))->assertForbidden();

    $this->actingAs($manager)->get(route('staff.edit', $showroomStaff))->assertOk();
});

test('managers cannot edit admins, even at their location', function () {
    $manager = User::factory()->manager()->at($this->showroom)->create();
    $admin = User::factory()->admin()->at($this->showroom)->create();

    $this->actingAs($manager)->get(route('staff.edit', $admin))->assertForbidden();
    $this->actingAs($manager)->patch(route('staff.toggle-active', $admin))->assertForbidden();
});

test('the staff list shows staff of the current location', function () {
    $manager = User::factory()->manager()->at($this->showroom, $this->kandy)->create(['name' => 'Manager']);
    User::factory()->at($this->showroom)->create(['name' => 'Showroom Cashier']);
    User::factory()->at($this->kandy)->create(['name' => 'Kandy Cashier']);
    User::factory()->at($this->warehouse, $this->showroom)->create(['name' => 'Floating Storekeeper', 'role' => Role::Storekeeper]);
    User::factory()->at($this->warehouse)->create(['name' => 'Warehouse Only']);

    $this->actingAs($manager)->get(route('staff.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('staff.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Floating Storekeeper', 'Manager', 'Showroom Cashier'])
            // Linked to the showroom but based at the warehouse: listed, not editable.
            ->where('staff.data', fn ($rows) => collect($rows)->firstWhere('name', 'Floating Storekeeper')['can']['update'] === false));

    $this->post(route('location.switch'), ['location' => $this->kandy->id]);

    $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page->has('staff.data', 2));
});

test('admins see everyone with all locations', function () {
    $admin = User::factory()->admin()->at($this->warehouse)->create();
    User::factory()->count(3)->at($this->kandy)->create();

    $this->actingAs($admin)->withSession([CurrentLocation::SESSION_KEY => 'all'])
        ->get(route('staff.index'))
        ->assertInertia(fn (Assert $page) => $page->has('staff.data', 4));
});

test('a manager keeps links to locations they do not run when editing', function () {
    $manager = User::factory()->manager()->at($this->showroom, $this->kandy)->create();
    $staff = User::factory()->at($this->showroom, $this->warehouse)->create();

    $this->actingAs($manager)
        ->put(route('staff.update', $staff), staffPayload([
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => '',
            'password_confirmation' => '',
            'pin' => '',
            'home_location_id' => $this->showroom->id,
            'location_ids' => [$this->kandy->id],
        ]))
        ->assertSessionHasNoErrors();

    expect($staff->fresh()->locationIds())->toEqualCanonicalizing([$this->showroom->id, $this->kandy->id, $this->warehouse->id]);
});

test('nobody can change their own role or deactivate themselves', function () {
    $manager = User::factory()->manager()->at($this->showroom)->create();

    $payload = staffPayload(['name' => $manager->name, 'email' => $manager->email, 'password' => '', 'password_confirmation' => '', 'pin' => '', 'home_location_id' => $this->showroom->id]);

    $this->actingAs($manager)->put(route('staff.update', $manager), [...$payload, 'role' => 'cashier', 'is_active' => true])
        ->assertSessionHasErrors('role');
    $this->actingAs($manager)->put(route('staff.update', $manager), [...$payload, 'role' => 'manager', 'is_active' => false])
        ->assertSessionHasErrors('is_active');
    $this->actingAs($manager)->patch(route('staff.toggle-active', $manager))->assertForbidden();

    expect($manager->fresh())->role->toBe(Role::Manager)->is_active->toBeTrue();
});

test('role changes and deactivation are audited', function () {
    $admin = User::factory()->admin()->at($this->warehouse)->create();
    $staff = User::factory()->at($this->showroom)->create();

    $this->actingAs($admin)->put(route('staff.update', $staff), staffPayload([
        'name' => $staff->name, 'email' => $staff->email, 'role' => 'storekeeper',
        'password' => '', 'password_confirmation' => '', 'pin' => '', 'home_location_id' => $this->showroom->id,
    ]))->assertSessionHasNoErrors();

    $this->actingAs($admin)->patch(route('staff.toggle-active', $staff));

    $log = AuditLog::where('action', 'user.role_changed')->firstOrFail();

    expect($log->before)->toBe(['role' => 'cashier'])
        ->and($log->after)->toBe(['role' => 'storekeeper'])
        ->and($log->location_id)->toBe($this->showroom->id)
        ->and(AuditLog::where('action', 'user.deactivated')->exists())->toBeTrue()
        ->and($staff->fresh()->is_active)->toBeFalse();
});
