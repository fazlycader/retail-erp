<?php

/*
| Used by tests/Feature/CounterTest.php: takes $count numbers from one counter, each in its own
| transaction, and rolls every 5th transaction back (its number must be handed out again).
| Prints the committed numbers as JSON. Usage: php counter-worker.php <count> <scope>
*/

use App\Support\Counter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$count, $scope] = [(int) $argv[1], $argv[2]];
$committed = [];

for ($i = 1; $i <= $count; $i++) {
    try {
        // One attempt only: a deadlock would fail the test instead of being retried away.
        $committed[] = DB::transaction(function () use ($i, $scope) {
            $number = Counter::next('test', $scope);
            usleep(random_int(0, 1500)); // hold the lock a little, like a real document save

            if ($i % 5 === 0) {
                throw new RuntimeException('rollback');
            }

            return $number;
        }, 1);
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'rollback') {
            throw $e;
        }
    }
}

echo json_encode($committed);
