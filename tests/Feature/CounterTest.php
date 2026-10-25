<?php

use App\Support\Counter;
use App\Support\SyncVersion;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('counters count per type and scope', function () {
    expect(Counter::next('invoice', 'location:1'))->toBe(1)
        ->and(Counter::next('invoice', 'location:1'))->toBe(2)
        ->and(Counter::next('invoice', 'location:2'))->toBe(1)
        ->and(Counter::next('grn', 'location:1'))->toBe(1)
        ->and(Counter::next('transfer'))->toBe(1)
        ->and(Counter::current('invoice', 'location:1'))->toBe(2)
        ->and(Counter::current('quotation', 'location:1'))->toBe(0);
});

test('a rolled back number is handed out again', function () {
    Counter::next('invoice', 'x');

    try {
        DB::transaction(function () {
            expect(Counter::next('invoice', 'x'))->toBe(2);

            throw new RuntimeException('save failed');
        });
    } catch (RuntimeException) {
        //
    }

    expect(Counter::next('invoice', 'x'))->toBe(2);
});

test('sync versions count per location', function () {
    DB::transaction(fn () => expect(SyncVersion::next(1))->toBe(1)->and(SyncVersion::next(1))->toBe(2));

    expect(SyncVersion::current(1))->toBe(2)->and(SyncVersion::current(2))->toBe(0);
});

/*
| Gap-free under real concurrency needs real MySQL (SQLite serialises everything). Uses the
| MYSQL_TEST_DATABASE database (default retail_erp_testing, wiped by this test) on the DB_* host
| from .env; skipped when it can't connect.
*/
test('counters stay gap-free with parallel writers on MySQL', function () {
    $env = mysqlTestEnv();

    try {
        new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
    } catch (PDOException $e) {
        $this->markTestSkipped("MySQL test database not available ({$e->getMessage()}).");
    }

    $lock = lockMysqlTestDatabase();

    $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), $env);
    $migrate->mustRun();

    [$processes, $perProcess] = [8, 50];
    $workers = collect(range(1, $processes))->map(function () use ($env, $perProcess) {
        $process = new Process([PHP_BINARY, 'tests/Support/counter-worker.php', (string) $perProcess, 'parallel'], base_path(), $env, null, 120);
        $process->start();

        return $process;
    });

    $numbers = $workers->flatMap(function (Process $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return json_decode($process->getOutput(), true);
    })->sort()->values()->all();

    // Every 5th transaction rolled back: 8 × 40 committed numbers, exactly 1…320, no gaps or repeats.
    $committed = $processes * ($perProcess - intdiv($perProcess, 5));

    expect($numbers)->toBe(range(1, $committed));
})->group('mysql');

/**
 * @return array<string, string>
 */
function mysqlTestEnv(): array
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
