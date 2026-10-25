<?php

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\TransferStatus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\TransferService;
use App\Support\Quantity;

function createTransferVariant(string $sku = 'TRF-SOFA-01'): ProductVariant
{
    $category = Category::query()->first() ?? Category::create(['name' => 'Sofas', 'slug' => 'sofas']);

    $product = app(ProductService::class)->save(
        new Product,
        [
            'category_id' => $category->id,
            'name' => 'Transfer Item '.$sku,
            'slug' => strtolower($sku),
            'type' => ProductType::Stock,
            'is_active' => true,
        ],
        [],
        [
            [
                'value_ids' => [],
                'sku' => $sku,
                'price' => 12000000,
                'min_price' => 11000000,
                'online_price' => null,
                'reorder_level' => 0,
                'reorder_qty' => 0,
                'is_active' => true,
            ],
        ],
    );

    return $product->variants()->firstOrFail();
}

test('full transfer lifecycle moves stock from source to in-transit to destination', function () {
    $warehouse = Location::factory()->create(['code' => 'WHO']);
    $showroom = Location::factory()->create(['code' => 'SHW']);
    $manager = User::factory()->manager()->create(['home_location_id' => $warehouse->id]);
    $manager->assignLocations($warehouse->id, [$showroom->id]);

    $variant = createTransferVariant('TRF-FULL-01');
    app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $warehouse->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(10),
        'unit_cost' => 8000000,
    ]);

    $service = app(TransferService::class);

    $transfer = $service->create([
        'from_location_id' => $warehouse->id,
        'to_location_id' => $showroom->id,
        'status' => TransferStatus::Draft,
        'vehicle_note' => 'WP LB-9988',
        'lines' => [
            ['product_variant_id' => $variant->id, 'requested_qty' => Quantity::fromUnits(4)],
        ],
    ], $manager);

    expect($transfer->status)->toBe(TransferStatus::Draft);

    $service->submitRequest($transfer, $manager);
    expect($transfer->fresh()->status)->toBe(TransferStatus::Requested);

    // Dispatch: deducts 4 from warehouse, showroom still 0 (in transit on the transfer)
    $service->dispatch($transfer, $manager);
    expect($transfer->fresh()->status)->toBe(TransferStatus::Dispatched)
        ->and(StockLevel::query()->where('location_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(6))
        ->and((int) StockLevel::query()->where('location_id', $showroom->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(0);

    // Receive full quantity at showroom
    $service->receive($transfer, $manager);
    expect($transfer->fresh()->status)->toBe(TransferStatus::Received)
        ->and(StockLevel::query()->where('location_id', $showroom->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(4));

    $this->artisan('stock:verify')->assertSuccessful();
});

test('short receipt creates a discrepancy and manager can resolve by returning to source or writing off', function () {
    $warehouse = Location::factory()->create(['code' => 'WH2']);
    $showroom = Location::factory()->create(['code' => 'SH2']);
    $storekeeper = User::factory()->storekeeper()->create(['home_location_id' => $warehouse->id]);
    $storekeeper->assignLocations($warehouse->id, [$showroom->id]);

    $manager = User::factory()->manager()->create(['home_location_id' => $warehouse->id]);
    $manager->assignLocations($warehouse->id, [$showroom->id]);

    $variant = createTransferVariant('TRF-DISC-01');
    app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $warehouse->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(10),
        'unit_cost' => 5000000,
    ]);

    $service = app(TransferService::class);

    // Case 1: Return missing item to source
    $trf1 = $service->create([
        'from_location_id' => $warehouse->id,
        'to_location_id' => $showroom->id,
        'lines' => [
            ['product_variant_id' => $variant->id, 'requested_qty' => Quantity::fromUnits(4)],
        ],
    ], $storekeeper);

    $service->dispatch($trf1, $storekeeper);
    $line1 = $trf1->lines()->firstOrFail();

    // Receive only 3 out of 4 (1 left behind on lorry)
    $service->receive($trf1, $storekeeper, [
        $line1->id => [
            'received_qty' => Quantity::fromUnits(3),
            'discrepancy_reason' => '1 unit left at warehouse loading bay',
        ],
    ]);

    expect($trf1->fresh()->status)->toBe(TransferStatus::PartiallyReceived)
        ->and($line1->fresh()->discrepancyQty())->toBe(Quantity::fromUnits(1));

    // Storekeeper cannot resolve discrepancies
    expect(fn () => $service->resolveDiscrepancy($trf1, $storekeeper, 'return_to_source'))
        ->toThrow(InvalidArgumentException::class, 'Only a manager or admin');

    // Manager resolves by returning to source: warehouse goes from 6 -> 7, showroom has 3
    $service->resolveDiscrepancy($trf1, $manager, 'return_to_source', 'Found in loading bay');

    expect($trf1->fresh()->status)->toBe(TransferStatus::Closed)
        ->and(StockLevel::query()->where('location_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(7))
        ->and(StockLevel::query()->where('location_id', $showroom->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(3));

    // Case 2: Write off missing item as loss
    $trf2 = $service->create([
        'from_location_id' => $warehouse->id,
        'to_location_id' => $showroom->id,
        'lines' => [
            ['product_variant_id' => $variant->id, 'requested_qty' => Quantity::fromUnits(2)],
        ],
    ], $storekeeper);

    $service->dispatch($trf2, $storekeeper);
    $line2 = $trf2->lines()->firstOrFail();

    $service->receive($trf2, $storekeeper, [
        $line2->id => [
            'received_qty' => Quantity::fromUnits(1),
            'discrepancy_reason' => 'Lost in transit',
        ],
    ]);

    $service->resolveDiscrepancy($trf2, $manager, 'write_off', 'Written off after investigation');

    expect($trf2->fresh()->status)->toBe(TransferStatus::Closed)
        ->and(StockLevel::query()->where('location_id', $warehouse->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(5))
        ->and(StockLevel::query()->where('location_id', $showroom->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(4))
        ->and(StockMovement::query()->where('reference_type', 'transfer')->where('reference_id', $trf2->id)->where('type', MovementType::AdjustmentOut)->exists())
        ->toBeTrue();

    $this->artisan('stock:verify')->assertSuccessful();
});
