<?php

/*
| Used by tests/Feature/InvoiceConcurrencyTest.php: confirms the given draft sales orders (each
| confirmation takes an invoice number) and rolls every Nth confirmation back.
*/

use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Sales\SalesOrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$userId, $rollbackEvery, $orderIds] = [(int) $argv[1], (int) $argv[2], array_map('intval', explode(',', $argv[3]))];

$user = User::query()->findOrFail($userId);
$service = app(SalesOrderService::class);
$confirmed = [];

foreach ($orderIds as $i => $id) {
    $rollBack = ($i + 1) % $rollbackEvery === 0;

    try {
        DB::transaction(function () use ($service, $user, $id, $rollBack) {
            $service->confirm(SalesOrder::query()->findOrFail($id), $user);

            if ($rollBack) {
                throw new DomainException('roll back');
            }
        }, 5); // like a till: a deadlock on the first-ever number of a scope is retried
        $confirmed[] = $id;
    } catch (DomainException) {
        // rolled back on purpose: its invoice number must be handed out again
    }
}

echo json_encode($confirmed);
