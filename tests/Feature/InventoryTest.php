<?php

use App\Enums\AdjustmentReason;
use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\InsufficientStock;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\OpeningStockImportService;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockService;
use App\Support\Quantity;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

function createStockVariant(string $sku = 'SOFA-TEST-01', int $price = 10000000, bool $serialized = false): ProductVariant
{
    $category = Category::query()->first() ?? Category::create(['name' => 'Living Room', 'slug' => 'living-room']);

    $product = app(ProductService::class)->save(
        new Product,
        [
            'category_id' => $category->id,
            'name' => 'Test Product '.$sku,
            'slug' => strtolower($sku),
            'type' => ProductType::Stock,
            'is_serialized' => $serialized,
            'is_active' => true,
        ],
        [],
        [
            [
                'value_ids' => [],
                'sku' => $sku,
                'price' => $price,
                'min_price' => $price,
                'online_price' => null,
                'reorder_level' => Quantity::fromUnits(2),
                'reorder_qty' => Quantity::fromUnits(5),
                'is_active' => true,
            ],
        ],
    );

    return $product->variants()->firstOrFail();
}

test('movement ledger equals stock levels after random operations and stock:verify passes', function () {
    $loc1 = Location::factory()->create(['code' => 'WH1']);
    $loc2 = Location::factory()->create(['code' => 'SR1']);
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $v1 = createStockVariant('SKU-RAND-1');
    $v2 = createStockVariant('SKU-RAND-2');

    $stock = app(StockService::class);
    $reservations = app(ReservationService::class);

    // Seed initial opening stock so random operations stay positive
    $stock->moveMany([
        ['product_variant_id' => $v1->id, 'location_id' => $loc1->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 500000],
        ['product_variant_id' => $v1->id, 'location_id' => $loc2->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 500000],
        ['product_variant_id' => $v2->id, 'location_id' => $loc1->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 800000],
        ['product_variant_id' => $v2->id, 'location_id' => $loc2->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(100), 'unit_cost' => 800000],
    ]);

    $inboundTypes = [MovementType::PurchaseReceipt, MovementType::SaleReturn, MovementType::AdjustmentIn, MovementType::CountGain];
    $outboundTypes = [MovementType::Sale, MovementType::SupplierReturn, MovementType::AdjustmentOut, MovementType::Damage, MovementType::CountLoss];

    for ($i = 0; $i < 30; $i++) {
        $variant = $i % 2 === 0 ? $v1 : $v2;
        $loc = $i % 3 === 0 ? $loc1 : $loc2;
        $isInbound = $i % 2 === 0;
        $type = $isInbound ? $inboundTypes[$i % count($inboundTypes)] : $outboundTypes[$i % count($outboundTypes)];
        $units = ($i % 3) + 1;

        $stock->move([
            'product_variant_id' => $variant->id,
            'location_id' => $loc->id,
            'type' => $type,
            'quantity' => $type->sign() * Quantity::fromUnits($units),
            'unit_cost' => 520000,
            'note' => "Random op #{$i}",
        ]);
    }

    $res1 = $reservations->reserve($v1, $loc1->id, Quantity::fromUnits(3));
    $res2 = $reservations->reserve($v2, $loc2->id, Quantity::fromUnits(2));
    $reservations->release($res1);
    $reservations->fulfil($res2);

    // Reserve one more active reservation to verify active reservations check in stock:verify
    $reservations->reserve($v1, $loc1->id, Quantity::fromUnits(4));

    $this->artisan('stock:verify')->assertSuccessful();
});

test('stock movements are immutable and cannot be updated or deleted', function () {
    $loc = Location::factory()->create(['code' => 'IMM']);
    $variant = createStockVariant('SKU-IMM-01');

    $movement = app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(5),
        'unit_cost' => 100000,
    ]);

    expect(fn () => $movement->update(['quantity' => Quantity::fromUnits(10)]))
        ->toThrow(LogicException::class, 'Stock movements are immutable');

    expect(fn () => $movement->delete())
        ->toThrow(LogicException::class, 'Stock movements are immutable');
});

test('negative stock is refused when allow_negative_stock is false and allowed when true', function () {
    $loc = Location::factory()->create(['code' => 'NEG']);
    $variant = createStockVariant('SKU-NEG-01');
    $stock = app(StockService::class);

    $stock->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(2),
        'unit_cost' => 100000,
    ]);

    expect(fn () => $stock->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Sale,
        'quantity' => -Quantity::fromUnits(3),
    ]))->toThrow(InsufficientStock::class);

    // Reserve 1 of the 2 units; selling 2 units must now fail because only 1 is available
    app(ReservationService::class)->reserve($variant, $loc->id, Quantity::fromUnits(1));

    expect(fn () => $stock->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Sale,
        'quantity' => -Quantity::fromUnits(2),
    ]))->toThrow(InsufficientStock::class);

    // Enable allow_negative_stock
    CompanySetting::current()->update(['allow_negative_stock' => true]);

    $stock->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Sale,
        'quantity' => -Quantity::fromUnits(5),
    ]);

    expect(StockLevel::query()->where('location_id', $loc->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(-Quantity::fromUnits(3));
});

test('weighted average cost updates on opening stock and purchase receipts', function () {
    $loc = Location::factory()->create(['code' => 'WAC']);
    $variant = createStockVariant('SKU-WAC-01');
    $stock = app(StockService::class);

    // 10 units @ Rs. 1,000.00 (100,000 cents)
    $stock->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(10),
        'unit_cost' => 100000,
    ]);

    expect($variant->fresh()->average_cost)->toBe(100000);

    // Receive 10 more units @ Rs. 2,000.00 (200,000 cents) -> average cost = Rs. 1,500.00 (150,000 cents)
    $stock->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::PurchaseReceipt,
        'quantity' => Quantity::fromUnits(10),
        'unit_cost' => 200000,
    ]);

    $variant->refresh();
    expect($variant->average_cost)->toBe(150000)
        ->and($variant->last_cost)->toBe(200000);
});

test('adjustments above adjustment_approval_value require a manager PIN', function () {
    $loc = Location::factory()->create(['code' => 'ADJ']);
    $storekeeper = User::factory()->storekeeper()->create(['home_location_id' => $loc->id]);
    $storekeeper->assignLocations($loc->id);

    $manager = User::factory()->manager()->create(['home_location_id' => $loc->id, 'pin' => '4321']);
    $manager->assignLocations($loc->id);

    // Threshold = Rs. 50,000 (5,000,000 cents)
    CompanySetting::current()->update(['adjustment_approval_value' => 5000000]);

    $variant = createStockVariant('SKU-ADJ-01');
    app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(10),
        'unit_cost' => 3000000, // Rs. 30,000 each
    ]);

    $service = app(AdjustmentService::class);

    // 1 unit damaged = Rs. 30,000 (below Rs. 50,000 threshold) -> no PIN needed
    $small = $service->create([
        'location_id' => $loc->id,
        'reason' => AdjustmentReason::Damaged,
        'lines' => [
            ['product_variant_id' => $variant->id, 'quantity' => -Quantity::fromUnits(1)],
        ],
    ], $storekeeper);

    expect($small->total_value)->toBe(3000000);

    // 2 units damaged = Rs. 60,000 (above Rs. 50,000 threshold) -> refuses without manager PIN
    expect(fn () => $service->create([
        'location_id' => $loc->id,
        'reason' => AdjustmentReason::Damaged,
        'lines' => [
            ['product_variant_id' => $variant->id, 'quantity' => -Quantity::fromUnits(2)],
        ],
    ], $storekeeper))->toThrow(ApprovalRefused::class);

    // Succeeds with manager PIN
    $large = $service->create([
        'location_id' => $loc->id,
        'reason' => AdjustmentReason::Damaged,
        'approver_id' => $manager->id,
        'pin' => '4321',
        'lines' => [
            ['product_variant_id' => $variant->id, 'quantity' => -Quantity::fromUnits(2)],
        ],
    ], $storekeeper);

    expect($large->approved_by)->toBe($manager->id)
        ->and($large->total_value)->toBe(6000000);

    $this->artisan('stock:verify')->assertSuccessful();
});

test('opening stock import runs dry run first and commits opening movements and serialized units', function () {
    $loc = Location::factory()->create(['code' => 'OPN']);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $normalVariant = createStockVariant('SOFA-OPN-01', 15000000, false);
    $serialVariant = createStockVariant('RECL-OPN-01', 12000000, true);

    $csv = implode("\n", [
        'sku,quantity,unit_cost,serials,note',
        'SOFA-OPN-01,5,95000.00,,Initial showroom stock',
        'RECL-OPN-01,2,80000.00,"SN-OPN-101, SN-OPN-102",Serialized recliners',
        'UNKNOWN-SKU,3,1000.00,,Should fail in dry run',
    ]);

    $file = UploadedFile::fake()->createWithContent('opening.csv', $csv);
    $service = app(OpeningStockImportService::class);

    $import = $service->uploadAndDryRun($file, $loc, $admin);

    expect($import->counts)->toBe(['create' => 2, 'fail' => 1])
        ->and((int) StockLevel::query()->where('location_id', $loc->id)->sum('on_hand'))->toBe(0);

    $committed = $service->commit($import, $admin);

    expect($committed->counts)->toBe(['created' => 2, 'failed' => 1])
        ->and(StockLevel::query()->where('location_id', $loc->id)->where('product_variant_id', $normalVariant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(5))
        ->and(StockLevel::query()->where('location_id', $loc->id)->where('product_variant_id', $serialVariant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(2))
        ->and(StockUnit::query()->whereIn('serial_number', ['SN-OPN-101', 'SN-OPN-102'])->count())->toBe(2);

    // Mark SN-OPN-101 as a display piece
    $unit = StockUnit::query()->where('serial_number', 'SN-OPN-101')->firstOrFail();
    $this->patch(route('stock.units.update', $unit), ['condition' => StockUnitCondition::Display->value])
        ->assertRedirect();

    expect($unit->fresh()->condition)->toBe(StockUnitCondition::Display)
        ->and($unit->fresh()->status)->toBe(StockUnitStatus::InStock);

    $this->artisan('stock:verify')->assertSuccessful();
});

test('cost props are omitted from stock pages for storekeepers and included for managers', function () {
    $loc = Location::factory()->create(['code' => 'CST']);
    $variant = createStockVariant('SKU-COST-01');

    app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(4),
        'unit_cost' => 7500000,
    ]);

    $storekeeper = User::factory()->storekeeper()->create(['home_location_id' => $loc->id]);
    $storekeeper->assignLocations($loc->id);

    $manager = User::factory()->manager()->create(['home_location_id' => $loc->id]);
    $manager->assignLocations($loc->id);

    $this->actingAs($storekeeper)
        ->get(route('stock.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Stock/Index')
            ->where('canViewCost', false)
            ->missing('variants.data.0.average_cost')
            ->missing('variants.data.0.total_value'));

    $this->actingAs($storekeeper)
        ->get(route('stock.show', $variant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Stock/Show')
            ->where('canViewCost', false)
            ->missing('variant.average_cost')
            ->missing('locationCards.0.value')
            ->missing('movements.data.0.unit_cost'));

    $this->actingAs($manager)
        ->get(route('stock.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Stock/Index')
            ->where('canViewCost', true)
            ->where('variants.data.0.average_cost', 7500000)
            ->where('variants.data.0.total_value', 30000000));
});
