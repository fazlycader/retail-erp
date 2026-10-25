<?php

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Support\Quantity;
use Symfony\Component\Process\Process;

function inventoryMysqlEnv(): array
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

test('two parallel operations racing for the last unit on MySQL: exactly one wins and no deadlocks under 8 parallel transfers', function () {
    $env = inventoryMysqlEnv();

    try {
        new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
    } catch (PDOException $e) {
        $this->markTestSkipped("MySQL test database not available ({$e->getMessage()}).");
    }

    $lock = lockMysqlTestDatabase();

    $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), $env);
    $migrate->mustRun();

    // Configure our local connection to talk to the MySQL test database for setup & verification
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => $env['DB_HOST'],
        'database.connections.mysql.port' => $env['DB_PORT'],
        'database.connections.mysql.database' => $env['DB_DATABASE'],
        'database.connections.mysql.username' => $env['DB_USERNAME'],
        'database.connections.mysql.password' => $env['DB_PASSWORD'],
    ]);
    DB::purge('mysql');

    $user = User::factory()->admin()->create();
    $locA = Location::factory()->create(['code' => 'LOCA']);
    $locB = Location::factory()->create(['code' => 'LOCB']);
    $category = Category::create(['name' => 'Concurrency', 'slug' => 'concurrency']);

    $products = app(ProductService::class);
    $raceProduct = $products->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Last Unit Sofa', 'slug' => 'last-unit-sofa', 'type' => ProductType::Stock, 'is_active' => true],
        [],
        [['value_ids' => [], 'sku' => 'RACE-LAST-01', 'price' => 100000, 'min_price' => 100000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );
    $raceVariant = $raceProduct->variants()->firstOrFail();

    $stock = app(StockService::class);

    // Put exactly 1 unit at locA
    $stock->move([
        'product_variant_id' => $raceVariant->id,
        'location_id' => $locA->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(1),
        'unit_cost' => 50000,
        'user_id' => $user->id,
    ]);

    // 1. Two parallel processes racing for the last unit at locA (e.g., sale vs transfer out)
    $raceWorkers = collect([1, 2])->map(function (int $workerNum) use ($env, $raceVariant, $locA, $user) {
        $process = new Process(
            [PHP_BINARY, 'tests/Support/stock-race-worker.php', (string) $raceVariant->id, (string) $locA->id, (string) $user->id, (string) $workerNum],
            base_path(),
            $env,
            null,
            60,
        );
        $process->start();

        return $process;
    });

    $raceOutcomes = $raceWorkers->map(function (Process $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return json_decode($process->getOutput(), true);
    });

    $wins = $raceOutcomes->where('status', 'won')->count();
    $insufficient = $raceOutcomes->where('status', 'insufficient')->count();

    expect($wins)->toBe(1)
        ->and($insufficient)->toBe(1)
        ->and(StockLevel::query()->where('location_id', $locA->id)->where('product_variant_id', $raceVariant->id)->value('on_hand'))
        ->toBe(0);

    // 2. 8 parallel transfers moving multiple variants back and forth between locA and locB without deadlocks
    $trfProduct1 = $products->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Parallel Item 1', 'slug' => 'parallel-item-1', 'type' => ProductType::Stock, 'is_active' => true],
        [],
        [['value_ids' => [], 'sku' => 'PAR-TRF-01', 'price' => 100000, 'min_price' => 100000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );
    $trfProduct2 = $products->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Parallel Item 2', 'slug' => 'parallel-item-2', 'type' => ProductType::Stock, 'is_active' => true],
        [],
        [['value_ids' => [], 'sku' => 'PAR-TRF-02', 'price' => 100000, 'min_price' => 100000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );
    $v1 = $trfProduct1->variants()->firstOrFail();
    $v2 = $trfProduct2->variants()->firstOrFail();

    // Seed 100 units of each at both locations
    $stock->moveMany([
        ['product_variant_id' => $v1->id, 'location_id' => $locA->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 50000, 'user_id' => $user->id],
        ['product_variant_id' => $v1->id, 'location_id' => $locB->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 50000, 'user_id' => $user->id],
        ['product_variant_id' => $v2->id, 'location_id' => $locA->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 50000, 'user_id' => $user->id],
        ['product_variant_id' => $v2->id, 'location_id' => $locB->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 50000, 'user_id' => $user->id],
    ]);

    $transferWorkers = collect(range(1, 8))->map(function (int $workerNum) use ($env, $locA, $locB, $v1, $v2, $user) {
        // Odd workers transfer locA -> locB, even workers transfer locB -> locA
        [$fromId, $toId] = $workerNum % 2 === 1 ? [$locA->id, $locB->id] : [$locB->id, $locA->id];

        $process = new Process(
            [
                PHP_BINARY,
                'tests/Support/transfer-worker.php',
                (string) $fromId,
                (string) $toId,
                (string) $v1->id,
                (string) $v2->id,
                (string) $user->id,
            ],
            base_path(),
            $env,
            null,
            120,
        );
        $process->start();

        return $process;
    });

    foreach ($transferWorkers as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().' '.$process->getErrorOutput());
        $data = json_decode($process->getOutput(), true);
        expect($data['ok'] ?? false)->toBeTrue();
    }

    // Verify ledger integrity across all parallel transfers
    $verify = new Process([PHP_BINARY, 'artisan', 'stock:verify'], base_path(), $env);
    $verify->mustRun();
    expect($verify->isSuccessful())->toBeTrue();

    config(['database.default' => 'sqlite']);
})->group('mysql');
