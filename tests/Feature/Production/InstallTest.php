<?php

use App\Enums\Role;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\User;
use App\Support\ErrorAlerts;
use App\Support\ProductionGuard;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('app:install seeds only the essentials', function () {
    $this->artisan('app:install')->assertSuccessful();

    expect(Location::query()->pluck('code')->sort()->values()->all())->toBe(['HO', 'MS'])
        ->and(PaymentMethod::query()->count())->toBe(8)
        ->and(Register::query()->count())->toBe(2)
        ->and(CompanySetting::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0);

    $this->artisan('app:install')->assertSuccessful(); // safe to run again
    expect(Location::query()->count())->toBe(2)->and(PaymentMethod::query()->count())->toBe(8);
});

test('app:create-admin creates an admin with a strong password', function () {
    $this->artisan('app:install');

    $this->artisan('app:create-admin')
        ->expectsQuestion('Name', 'Fazly')
        ->expectsQuestion('Email (used to sign in)', 'Owner@Example.lk')
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->expectsQuestion('Type the password again', 'short')
        ->assertFailed();

    $this->artisan('app:create-admin')
        ->expectsQuestion('Name', 'Fazly')
        ->expectsQuestion('Email (used to sign in)', 'Owner@Example.lk')
        ->expectsQuestion('Password (at least 12 characters)', 'Teak-and-tea-2026')
        ->expectsQuestion('Type the password again', 'Teak-and-tea-2026')
        ->assertSuccessful();

    $admin = User::query()->sole();
    expect($admin->role)->toBe(Role::Admin)->and($admin->email)->toBe('owner@example.lk')
        ->and(Hash::check('Teak-and-tea-2026', $admin->password))->toBeTrue();
});

test('demo data refuses to load in production', function () {
    $this->artisan('app:install');
    app()->detectEnvironment(fn () => 'production');

    app()->call([app(DemoSeeder::class), 'run']);

    expect(User::query()->count())->toBe(0)->and(Product::query()->count())->toBe(0)->and(Customer::query()->count())->toBe(0);
    $this->artisan('demo:reset', ['--force' => true])->assertFailed();
});

test('the production guard lists unsafe settings', function () {
    $connection = config('database.default');
    config([
        'app.key' => '', 'app.debug' => true, 'app.url' => 'http://erp.example.lk', 'database.default' => 'sqlite',
        'queue.default' => 'sync', 'cache.default' => 'array', 'session.driver' => 'file', 'session.secure' => false,
        'mail.default' => 'array', 'app.timezone' => 'UTC',
    ]);

    expect(ProductionGuard::problems())->toHaveCount(10);

    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false, 'app.url' => 'https://erp.example.lk',
        'database.default' => 'mysql', 'database.connections.mysql.database' => 'erp', 'database.connections.mysql.username' => 'erp',
        'queue.default' => 'database', 'cache.default' => 'database', 'session.driver' => 'database', 'session.secure' => true,
        'mail.default' => 'smtp', 'mail.from.address' => 'invoices@erp.example.lk', 'app.timezone' => 'Asia/Colombo',
    ]);

    expect(ProductionGuard::problems())->toBe([]);
    config(['database.default' => $connection]);
});

test('error alerts: unexpected errors are emailed in production, once per 15 minutes, expected ones never', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['security.error_alert_email' => 'owner@example.lk']);
    Mail::shouldReceive('raw')->once();

    $fire = fn () => new RuntimeException('Database is on fire');
    ErrorAlerts::report($fire());
    ErrorAlerts::report($fire()); // same error, same place: throttled
    ErrorAlerts::report(new NotFoundHttpException);
    ErrorAlerts::report(ValidationException::withMessages(['x' => 'y']));
});

test('branded error pages', function () {
    $this->artisan('app:install');
    CompanySetting::current()->update(['name' => 'Teak House']);

    $this->get('/this-page-does-not-exist')->assertNotFound()->assertSee('Page not found')->assertSee('Teak House');
});

test('app:test-mail sends a test email and rejects bad addresses', function () {
    Mail::fake();

    $this->artisan('app:test-mail', ['to' => 'owner@example.lk'])->assertSuccessful();
    $this->artisan('app:test-mail', ['to' => 'not-an-email'])->assertFailed();
});
