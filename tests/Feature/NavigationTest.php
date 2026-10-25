<?php

use App\Enums\Role;
use App\Models\User;
use App\Support\Navigation;
use Inertia\Testing\AssertableInertia as Assert;

test('the sidebar only lists modules the role can open', function (Role $role, array $expected) {
    $user = User::factory()->role($role)->create();
    $pageRoute = match ($role) {
        Role::Workshop => 'workshop.board',
        Role::Driver => 'driver.index',
        default => 'dashboard',
    };
    $component = match ($role) {
        Role::Workshop => 'Admin/Workshop/Board',
        Role::Driver => 'Driver/Index',
        default => 'Admin/Dashboard',
    };

    $this->actingAs($user)
        ->get(route($pageRoute))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('navigation', fn ($items) => collect($items)->pluck('key')->all() === $expected));
})->with([
    'admin' => [Role::Admin, ['dashboard', 'pos', 'sales', 'installments', 'customers', 'products', 'stock', 'transfers', 'workshop', 'deliveries', 'purchasing', 'suppliers', 'cash', 'reports', 'audit', 'staff', 'locations', 'online', 'settings']],
    'manager' => [Role::Manager, ['dashboard', 'pos', 'sales', 'installments', 'customers', 'products', 'stock', 'transfers', 'workshop', 'deliveries', 'purchasing', 'suppliers', 'cash', 'reports', 'audit', 'staff', 'locations', 'online']],
    'cashier' => [Role::Cashier, ['dashboard', 'pos', 'sales', 'installments', 'customers', 'products', 'deliveries', 'cash']],
    'storekeeper' => [Role::Storekeeper, ['dashboard', 'products', 'stock', 'transfers', 'workshop', 'deliveries', 'purchasing', 'suppliers']],
    'accountant' => [Role::Accountant, ['dashboard', 'sales', 'installments', 'customers', 'products', 'purchasing', 'suppliers', 'reports']],
    'workshop' => [Role::Workshop, ['workshop']],
    'driver' => [Role::Driver, ['driver']],
]);

test('every module route allows exactly the roles Navigation lists', function (Role $role) {
    $user = User::factory()->role($role)->create();

    foreach (Navigation::modules() as $key => $module) {
        $allowed = in_array($role, $module['roles'], true);

        $response = $this->actingAs($user)->get(route($module['route']));
        if ($allowed && $key === 'dashboard' && in_array($role, [Role::Workshop, Role::Driver], true)) {
            $response->assertRedirect(route($role === Role::Workshop ? 'workshop.board' : 'driver.index'));
        } else {
            $response->assertStatus($allowed ? 200 : 403, "{$role->value} → {$key}");
        }
    }
})->with(Role::cases());

test('write routes are protected by role too', function () {
    $cashier = User::factory()->create();
    $manager = User::factory()->manager()->create();

    $this->actingAs($cashier)->post(route('staff.store'), [])->assertForbidden();
    $this->actingAs($cashier)->post(route('locations.store'), [])->assertForbidden();
    $this->actingAs($manager)->post(route('settings.update'), [])->assertForbidden();
    $this->actingAs($manager)->post(route('locations.store'), [])->assertForbidden();
});

test('guests are sent to the login page', function () {
    foreach (Navigation::modules() as $module) {
        $this->get(route($module['route']))->assertRedirect(route('login'));
    }
});
