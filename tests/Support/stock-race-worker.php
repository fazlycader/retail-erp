<?php

/*
| Used by tests/Feature/InventoryConcurrencyTest.php: races to claim the last unit of a variant
| at a location (worker 1 as a Sale, worker 2 as a TransferOut).
*/

use App\Enums\MovementType;
use App\Exceptions\InsufficientStock;
use App\Services\Inventory\StockService;
use App\Support\Quantity;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$variantId, $locationId, $userId, $workerNum] = [
    (int) $argv[1],
    (int) $argv[2],
    (int) $argv[3],
    (int) $argv[4],
];

$type = $workerNum % 2 === 1 ? MovementType::Sale : MovementType::TransferOut;

try {
    app(StockService::class)->move([
        'product_variant_id' => $variantId,
        'location_id' => $locationId,
        'type' => $type,
        'quantity' => -Quantity::fromUnits(1),
        'user_id' => $userId,
        'note' => "Race worker #{$workerNum}",
    ]);

    echo json_encode(['status' => 'won', 'worker' => $workerNum]);
} catch (InsufficientStock $e) {
    echo json_encode(['status' => 'insufficient', 'worker' => $workerNum, 'message' => $e->getMessage()]);
}
