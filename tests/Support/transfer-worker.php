<?php

/*
| Used by tests/Feature/InventoryConcurrencyTest.php: creates, dispatches and receives transfers
| between two locations across multiple variants in parallel.
*/

use App\Models\User;
use App\Services\Inventory\TransferService;
use App\Support\Quantity;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$fromId, $toId, $v1Id, $v2Id, $userId] = [
    (int) $argv[1],
    (int) $argv[2],
    (int) $argv[3],
    (int) $argv[4],
    (int) $argv[5],
];

$user = User::query()->findOrFail($userId);
$service = app(TransferService::class);

for ($i = 1; $i <= 3; $i++) {
    $lines = $i % 2 === 1
        ? [
            ['product_variant_id' => $v1Id, 'requested_qty' => Quantity::fromUnits(1)],
            ['product_variant_id' => $v2Id, 'requested_qty' => Quantity::fromUnits(1)],
        ]
        : [
            ['product_variant_id' => $v2Id, 'requested_qty' => Quantity::fromUnits(1)],
            ['product_variant_id' => $v1Id, 'requested_qty' => Quantity::fromUnits(1)],
        ];

    $transfer = $service->create([
        'from_location_id' => $fromId,
        'to_location_id' => $toId,
        'lines' => $lines,
    ], $user);

    $service->dispatch($transfer, $user);
    $service->receive($transfer, $user);
}

echo json_encode(['ok' => true]);
