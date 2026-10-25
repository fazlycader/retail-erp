<?php

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Sales\SalesOrderService;
use App\Support\Quantity;
use Symfony\Component\Process\Process;

function invoiceMysqlEnv(): array
{
    $dotenv = Dotenv\Dotenv::parse((string) @file_get_contents(base_path('.env')));

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_URL' => '',
        'DB_HOST' => getenv('MYSQL_TEST_HOST') ?: ($dotenv['DB_HOST'] ?? '127.0.0.1'),
        'DB_PORT' => getenv('MYSQL_TEST_PORT') ?: ($dotenv['DB_PORT'] ?? '3306'),
        'DB_DATABASE' => getenv('MYSQL_TEST_DATABASE') ?: 'retail_erp_testing',
        'DB_USERNAME' => getenv('MYSQL_TEST_USERNAME') ?: ($dotenv['DB_USERNAME'] ?? 'root'),
        'DB_PASSWORD' => getenv('MYSQL_TEST_PASSWORD') ?: ($dotenv['DB_PASSWORD'] ?? ''),
    ];
}

/*
| Invoice numbers are taken inside the confirmation transaction, so they must stay gap-free per
| location when several tills confirm at once and some confirmations roll back.
*/
test('invoice numbers stay gap-free per location under parallel confirmations on MySQL', function () {
    $env = invoiceMysqlEnv();

    try {
        new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
    } catch (PDOException $e) {
        $this->markTestSkipped("MySQL test database not available ({$e->getMessage()}).");
    }

    $lock = lockMysqlTestDatabase();

    (new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), $env))->mustRun();

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => $env['DB_HOST'],
        'database.connections.mysql.port' => $env['DB_PORT'],
        'database.connections.mysql.database' => $env['DB_DATABASE'],
        'database.connections.mysql.username' => $env['DB_USERNAME'],
        'database.connections.mysql.password' => $env['DB_PASSWORD'],
    ]);
    DB::purge('mysql');

    $admin = User::factory()->admin()->create();
    $showrooms = [Location::factory()->create(['code' => 'SHA']), Location::factory()->create(['code' => 'SHB'])];
    $category = Category::create(['name' => 'Invoices', 'slug' => 'invoices']);
    $product = app(ProductService::class)->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Side Table', 'slug' => 'side-table', 'type' => ProductType::Stock, 'is_active' => true],
        [],
        [['value_ids' => [], 'sku' => 'INV-RACE-01', 'price' => 100000, 'min_price' => 100000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );
    $variant = $product->variants()->firstOrFail();

    $orders = app(SalesOrderService::class);
    $ids = [];
    foreach ($showrooms as $showroom) {
        app(StockService::class)->move([
            'product_variant_id' => $variant->id,
            'location_id' => $showroom->id,
            'type' => MovementType::Opening,
            'quantity' => Quantity::fromUnits(500),
            'unit_cost' => 50000,
            'user_id' => $admin->id,
        ]);

        foreach (range(1, 40) as $i) {
            $ids[] = $orders->save([
                'location_id' => $showroom->id,
                'kind' => 'cash_and_carry',
                'lines' => [['product_variant_id' => $variant->id, 'quantity' => Quantity::fromUnits(1), 'unit_price' => 100000]],
            ], $admin)->id;
        }
    }

    // 8 workers, each confirming 10 orders interleaved across both showrooms; every 4th rolls back.
    shuffle($ids);
    $workers = collect(array_chunk($ids, 10))->map(function (array $chunk) use ($env, $admin) {
        $process = new Process([PHP_BINARY, 'tests/Support/invoice-worker.php', (string) $admin->id, '4', implode(',', $chunk)], base_path(), $env, null, 120);
        $process->start();

        return $process;
    });

    $confirmedIds = $workers->flatMap(function (Process $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return json_decode($process->getOutput(), true);
    });

    expect($confirmedIds)->toHaveCount(64);

    foreach ($showrooms as $showroom) {
        $numbers = SalesOrder::query()
            ->where('location_id', $showroom->id)
            ->whereNotNull('invoice_number')
            ->pluck('invoice_number')
            ->map(fn (string $n) => (int) substr($n, -6))
            ->sort()
            ->values()
            ->all();

        expect($numbers)->toBe(range(1, count($numbers)));
    }

    expect(SalesOrder::query()->where('status', SalesOrderStatus::Confirmed->value)->count())->toBe(64)
        ->and(SalesOrder::query()->whereNull('invoice_number')->where('status', SalesOrderStatus::Draft->value)->count())->toBe(16);

    unset($lock);
});
