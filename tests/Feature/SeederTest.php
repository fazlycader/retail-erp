<?php

use App\Enums\Role;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\DailyLocationSummary;
use App\Models\DayClose;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

test('a fresh install has two locations, company settings and one admin', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class); // safe to run twice

    expect(Location::orderBy('id')->pluck('name')->all())->toBe(['Head Office Warehouse', 'Main Showroom'])
        ->and(Location::where('code', 'HO')->value('type')->value)->toBe('warehouse')
        ->and(CompanySetting::count())->toBe(1)
        ->and(User::count())->toBe(1)
        ->and(User::first()->role)->toBe(Role::Admin);

    $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertRedirect(route('dashboard'));
});

test('the demo seeder adds staff across the locations', function () {
    $this->seed(DemoSeeder::class);

    $manager = User::where('email', 'manager@example.com')->firstOrFail();

    expect(User::count())->toBe(7)
        ->and(Location::count())->toBe(3)
        ->and($manager->role)->toBe(Role::Manager)
        ->and(Location::whereKey($manager->locationIds())->pluck('code')->sort()->values()->all())->toBe(['KDY', 'MS'])
        ->and($manager->checkPin('1234'))->toBeTrue();
});

test('the demo seeder records payments, a cheque and a return', function () {
    $this->seed(DemoSeeder::class);

    expect(Payment::query()->count())->toBeGreaterThanOrEqual(4)
        ->and(Payment::query()->whereNotNull('cheque_number')->exists())->toBeTrue()
        ->and(SalesReturn::query()->count())->toBe(1)
        ->and(CreditNote::query()->count())->toBe(1);
});

test('demo:reset refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:reset', ['--force' => true])->assertFailed();
});

test('demo history simulates past trading days through the real services', function () {
    config(['demo.history_days' => 3]);
    $this->seed(DemoSeeder::class);

    $days = DayClose::query()->where('status', 'closed')->count();
    expect(Shift::query()->where('status', '!=', 'open')->count())->toBeGreaterThanOrEqual(6)
        ->and(SalesOrder::query()->whereNotNull('invoice_number')->count())->toBeGreaterThan(10)
        ->and($days)->toBeGreaterThanOrEqual(4)
        ->and(DailyLocationSummary::query()->count())->toBe($days)
        ->and(Shift::query()->where('status', 'open')->count())->toBe(1); // today's demo shift
});
