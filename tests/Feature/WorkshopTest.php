<?php

use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\ProductionJobStatus;
use App\Enums\ProductionSource;
use App\Enums\ProductType;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderStatus;
use App\Enums\StockUnitStatus;
use App\Enums\SupplierProgress;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\ProductionStage;
use App\Models\ProductVariant;
use App\Models\SalesOrderLine;
use App\Models\SmsMessage;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\TransferService;
use App\Services\Production\CustomSpecService;
use App\Services\Production\JobPresenter;
use App\Services\Production\ProductionService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\SalesOrderService;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

function setupWorkshopFixture(): array
{
    $showroom = Location::query()->create([
        'name' => 'Colombo Showroom',
        'code' => 'MS',
        'type' => LocationType::Showroom,
        'is_active' => true,
    ]);

    $workshop = Location::query()->create([
        'name' => 'Moratuwa Workshop',
        'code' => 'MW',
        'type' => LocationType::Workshop,
        'is_active' => true,
    ]);

    $stages = ProductionStage::forWorkshop($workshop->id);

    CompanySetting::current()->fill([
        'workshop_location_id' => $workshop->id,
        'workshop_overhead_bp' => 1000, // 10%
        'workshop_lead_days' => 14,
        'sms_enabled' => true,
    ])->save();

    $manager = User::factory()->create([
        'name' => 'Showroom Manager',
        'role' => Role::Manager,
        'pin' => '1234',
        'is_active' => true,
    ]);
    $manager->assignLocations($showroom->id, [$workshop->id]);

    $craftsman = User::factory()->create([
        'name' => 'Master Craftsman',
        'role' => Role::Workshop,
        'pin' => '2345',
        'is_active' => true,
    ]);
    $craftsman->assignLocations($workshop->id);

    $supplier = Supplier::query()->create([
        'name' => 'Moratuwa Teak Craft',
        'is_active' => true,
    ]);

    $furnitureCat = Category::query()->create([
        'name' => 'Beds',
        'slug' => 'beds',
        'is_material' => false,
    ]);

    $materialCat = Category::query()->create([
        'name' => 'Raw Materials',
        'slug' => 'raw-materials',
        'is_material' => true,
    ]);

    $productService = app(ProductService::class);

    $bedProduct = $productService->save(
        new Product,
        [
            'category_id' => $furnitureCat->id,
            'name' => 'Kandyan Teak Bed',
            'slug' => 'kandyan-teak-bed',
            'type' => ProductType::MadeToOrder,
            'is_active' => true,
        ],
        [],
        [
            [
                'value_ids' => [],
                'sku' => 'BED-KDY-QN',
                'price' => 22000000,
                'min_price' => 20000000,
                'online_price' => null,
                'reorder_level' => 0,
                'reorder_qty' => 0,
                'is_active' => true,
                'suppliers' => [
                    [
                        'supplier_id' => $supplier->id,
                        'supplier_sku' => 'MTC-BED-QN',
                        'last_cost' => 15000000,
                        'is_preferred' => true,
                    ],
                ],
            ],
        ],
    );

    $velvetProduct = $productService->save(
        new Product,
        [
            'category_id' => $materialCat->id,
            'name' => 'Emerald Velvet Fabric',
            'slug' => 'emerald-velvet-fabric',
            'type' => ProductType::Stock,
            'unit' => 'm',
            'is_active' => true,
        ],
        [],
        [
            [
                'value_ids' => [],
                'sku' => 'MAT-VEL-EMR',
                'price' => 500000,
                'min_price' => 450000,
                'online_price' => null,
                'reorder_level' => 5000,
                'reorder_qty' => 20000,
                'is_active' => true,
            ],
        ],
    );

    /** @var ProductVariant $bedVariant */
    $bedVariant = $bedProduct->variants()->first();
    /** @var ProductVariant $velvetVariant */
    $velvetVariant = $velvetProduct->variants()->first();
    $velvetVariant->update(['average_cost' => 400000, 'last_cost' => 400000]); // Rs 4,000.00 / m

    // Seed 20.000 m of velvet at workshop
    app(StockService::class)->move([
        'product_variant_id' => $velvetVariant->id,
        'location_id' => $workshop->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(20),
        'unit_cost' => 400000,
        'user_id' => $manager->id,
    ]);

    $customer = Customer::query()->create([
        'name' => 'Anushka Jayawardena',
        'phone' => '+94771112233',
    ]);

    return compact('showroom', 'workshop', 'stages', 'manager', 'craftsman', 'supplier', 'bedProduct', 'bedVariant', 'velvetVariant', 'customer');
}

it('saves custom specs, records approval, and copies them when converting a quotation to a sales order', function () {
    $fx = setupWorkshopFixture();
    $specService = app(CustomSpecService::class);
    $quoteService = app(QuotationService::class);

    $spec = $specService->save(null, [
        'base_product_id' => $fx['bedProduct']->id,
        'width_mm' => 1950,
        'depth_mm' => 2100,
        'height_mm' => 1200,
        'material' => 'Burma Teak',
        'fabric_code' => 'VEL-EMR-04',
        'finish' => 'Natural Satin',
        'lead_time_days' => 21,
        'notes' => 'Recessed brass reading light cutouts',
    ]);

    $specService->recordApproval($spec, 'Anushka J.', today(), null, $fx['manager']);
    expect($spec->fresh()->isApproved())->toBeTrue()
        ->and($spec->summary())->toContain('1950×2100×1200 mm', 'Burma Teak', 'Fabric VEL-EMR-04');

    $quote = $quoteService->save([
        'customer_id' => $fx['customer']->id,
        'location_id' => $fx['showroom']->id,
        'valid_until' => today()->addDays(14)->toDateString(),
        'lines' => [
            [
                'is_custom' => true,
                'name_snapshot' => 'Bespoke King Bed',
                'custom_spec' => $spec->summary(),
                'custom_spec_id' => $spec->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 26000000,
            ],
        ],
    ], $fx['manager']);

    $order = $quoteService->convertToOrder($quote, [], $fx['manager']);
    $orderLine = $order->lines->first();

    expect($orderLine->custom_spec_id)->not->toBeNull()
        ->and($orderLine->custom_spec_id)->not->toBe($spec->id)
        ->and($orderLine->customSpec->material)->toBe('Burma Teak')
        ->and($orderLine->source)->toBe(SalesLineSource::Make);
});

it('runs the full workshop lifecycle: confirm order -> move stages -> issue & return fractional materials -> log labour -> complete -> transfer to showroom -> fulfil', function () {
    $fx = setupWorkshopFixture();
    $salesOrders = app(SalesOrderService::class);
    $production = app(ProductionService::class);
    $transfers = app(TransferService::class);
    $specService = app(CustomSpecService::class);

    $spec = $specService->save(null, [
        'width_mm' => 1800,
        'depth_mm' => 2000,
        'height_mm' => 1100,
        'material' => 'Solid Teak',
        'lead_time_days' => 10,
    ]);

    $order = $salesOrders->save([
        'customer_id' => $fx['customer']->id,
        'location_id' => $fx['showroom']->id,
        'lines' => [
            [
                'is_custom' => true,
                'name_snapshot' => 'Custom Teak Bed',
                'custom_spec' => $spec->summary(),
                'custom_spec_id' => $spec->id,
                'source' => SalesLineSource::Make->value,
                'fulfil_location_id' => $fx['showroom']->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 25000000,
            ],
        ],
    ], $fx['manager']);

    $order = $salesOrders->confirm($order, $fx['manager']);

    // 1. Job created at workshop & confirmation SMS queued
    $job = ProductionJob::query()->where('sales_order_id', $order->id)->firstOrFail();
    expect($job->source)->toBe(ProductionSource::Workshop)
        ->and($job->status)->toBe(ProductionJobStatus::Pending)
        ->and($job->workshop_location_id)->toBe($fx['workshop']->id)
        ->and(SmsMessage::query()->where('template_key', 'custom_confirmed')->count())->toBe(1);

    // 2. Move into first stage -> InProduction + SMS
    $firstStage = $fx['stages']->first();
    $job = $production->moveToStage($job, $firstStage, $fx['craftsman'], 'Frame cutting started');
    expect($job->status)->toBe(ProductionJobStatus::InProduction)
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::InProduction)
        ->and(SmsMessage::query()->where('template_key', 'custom_in_production')->count())->toBe(1);

    // 3. Issue 2.500 m (2500 milli-units) of velvet at Rs 4,000/m = Rs 10,000 (1,000,000 cents)
    $production->issueMaterial($job, $fx['velvetVariant'], 2500, $fx['craftsman'], 'Headboard panel');
    expect(StockLevel::query()->where('product_variant_id', $fx['velvetVariant']->id)->where('location_id', $fx['workshop']->id)->value('on_hand'))
        ->toBe(17500);

    // Return 0.500 m (500 milli-units) -> proportional return cost = Rs 2,000 (200,000 cents), net materials = Rs 8,000 (800,000 cents)
    $production->returnMaterial($job->fresh(), $fx['velvetVariant'], 500, $fx['craftsman'], 'Offcut returned');
    expect(StockLevel::query()->where('product_variant_id', $fx['velvetVariant']->id)->where('location_id', $fx['workshop']->id)->value('on_hand'))
        ->toBe(18000);

    // Non-material variants still reject fractional quantities
    expect(fn () => app(StockService::class)->move([
        'product_variant_id' => $fx['bedVariant']->id,
        'location_id' => $fx['workshop']->id,
        'type' => MovementType::Opening,
        'quantity' => 1500,
        'unit_cost' => 100000,
    ]))->toThrow(ValidationException::class);

    // 4. Log labour: 120 mins (2h) at Rs 1,000/hr (100,000 cents) = Rs 2,000 (200,000 cents)
    $production->addLabour($job->fresh(), [
        'user_id' => $fx['craftsman']->id,
        'work_date' => today()->toDateString(),
        'minutes' => 120,
        'hourly_rate' => 100000,
        'note' => 'Upholstery & frame assembly',
    ], $fx['manager']);

    // Cost check: materials 800,000 + labour 200,000 = 1,000,000; 10% overhead = 100,000; total = 1,100,000 cents
    $job->refresh();
    expect($job->materials_cost)->toBe(800000)
        ->and($job->labour_cost)->toBe(200000)
        ->and($job->overhead_cost)->toBe(100000)
        ->and($job->total_cost)->toBe(1100000);

    // 5. Complete workshop job -> creates serialized StockUnit at workshop, reserves for order line, marks order Ready, queues SMS
    $job = $production->complete($job, $fx['craftsman'], 'Passed final QC');
    expect($job->status)->toBe(ProductionJobStatus::Completed)
        ->and($job->stock_unit_id)->not->toBeNull()
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::Ready)
        ->and(SmsMessage::query()->where('template_key', 'custom_ready')->count())->toBe(1);

    $unit = StockUnit::query()->findOrFail($job->stock_unit_id);
    expect($unit->location_id)->toBe($fx['workshop']->id)
        ->and($unit->unit_cost)->toBe(1100000)
        ->and($unit->status)->toBe(StockUnitStatus::Reserved);

    /** @var SalesOrderLine $orderLine */
    $orderLine = $order->lines()->first();
    expect($orderLine->qty_reserved)->toBe(Quantity::fromUnits(1))
        ->and($orderLine->unit_cost_snapshot)->toBe(1100000)
        ->and($orderLine->fulfil_location_id)->toBe($fx['workshop']->id);

    // 6. Send finished piece from workshop to showroom via transfer -> reservation moves to showroom on receipt
    $transfer = $production->sendToLocation($job, $fx['showroom']->id, $fx['manager']);
    $transfer = $transfers->dispatch($transfer, $fx['manager']);

    $transferLine = $transfer->lines()->first();
    $transfers->receive($transfer, [
        [
            'transfer_line_id' => $transferLine->id,
            'received_qty' => Quantity::fromUnits(1),
        ],
    ], $fx['manager']);

    $orderLine->refresh();
    $unit->refresh();
    expect($unit->location_id)->toBe($fx['showroom']->id)
        ->and($unit->status)->toBe(StockUnitStatus::Reserved)
        ->and($orderLine->fulfil_location_id)->toBe($fx['showroom']->id)
        ->and($orderLine->qty_reserved)->toBe(Quantity::fromUnits(1));

    // Showroom can now fulfil the order line
    $order = $salesOrders->fulfil($order->fresh(), [
        [
            'sales_order_line_id' => $orderLine->id,
            'quantity' => Quantity::fromUnits(1),
        ],
    ], $fx['manager']);

    expect($order->status)->toBe(SalesOrderStatus::Fulfilled)
        ->and($unit->fresh()->status)->toBe(StockUnitStatus::Sold);
});

it('tracks supplier-sourced custom jobs and marks them Ready with reserved stock when the GRN is received', function () {
    $fx = setupWorkshopFixture();
    $salesOrders = app(SalesOrderService::class);
    $production = app(ProductionService::class);
    $grnService = app(GoodsReceiptService::class);

    $order = $salesOrders->save([
        'customer_id' => $fx['customer']->id,
        'location_id' => $fx['showroom']->id,
        'lines' => [
            [
                'product_variant_id' => $fx['bedVariant']->id,
                'source' => SalesLineSource::SupplierOrder->value,
                'fulfil_location_id' => $fx['showroom']->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 22000000,
            ],
        ],
    ], $fx['manager']);

    $order = $salesOrders->confirm($order, $fx['manager']);

    $job = ProductionJob::query()->where('sales_order_id', $order->id)->firstOrFail();
    expect($job->source)->toBe(ProductionSource::Supplier)
        ->and($job->purchase_order_id)->not->toBeNull()
        ->and($job->supplier_status)->toBe(SupplierProgress::Ordered);

    $job = $production->setSupplierStatus($job, SupplierProgress::InProduction, today()->addDays(7), 'Carving headboard', $fx['manager']);
    expect($job->status)->toBe(ProductionJobStatus::InProduction)
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::InProduction);

    // Receive the back-to-back PO at the showroom
    $po = $job->purchaseOrder;
    $poLine = $po->lines()->first();
    $po->update(['status' => 'sent']);

    $grnService->receive([
        'purchase_order_id' => $po->id,
        'lines' => [
            [
                'purchase_order_line_id' => $poLine->id,
                'product_variant_id' => $fx['bedVariant']->id,
                'received_qty' => Quantity::fromUnits(1),
                'damaged_qty' => 0,
                'unit_cost' => 15000000,
            ],
        ],
    ], $fx['manager']);

    $job->refresh();
    $orderLine = $order->lines()->first();

    expect($job->status)->toBe(ProductionJobStatus::Ready)
        ->and($job->supplier_status)->toBe(SupplierProgress::Received)
        ->and($orderLine->qty_reserved)->toBe(Quantity::fromUnits(1))
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::Ready)
        ->and(StockReservation::query()->where('reserved_for_id', $orderLine->id)->where('status', ReservationStatus::Active)->exists())->toBeTrue();
});

it('enforces workshop role boundaries and hides job costs from workshop staff', function () {
    $fx = setupWorkshopFixture();
    $salesOrders = app(SalesOrderService::class);

    $order = $salesOrders->save([
        'customer_id' => $fx['customer']->id,
        'location_id' => $fx['showroom']->id,
        'lines' => [
            [
                'product_variant_id' => $fx['bedVariant']->id,
                'source' => SalesLineSource::Make->value,
                'fulfil_location_id' => $fx['showroom']->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 22000000,
            ],
        ],
    ], $fx['manager']);
    $salesOrders->confirm($order, $fx['manager']);

    $job = ProductionJob::query()->firstOrFail()->load([
        'stage', 'workshopLocation', 'assignee',
        'salesOrder.customer', 'salesOrder.location',
        'salesOrderLine.variant.product', 'salesOrderLine.customSpec.baseProduct',
        'purchaseOrder.supplier', 'stockUnit',
        'stageLogs.toStage', 'stageLogs.fromStage', 'stageLogs.user',
        'materials.variant.product', 'materials.user',
        'labour.worker', 'labour.recordedBy',
    ]);

    // Workshop role receives null for cost and labour in JobPresenter
    $forCraftsman = JobPresenter::detail($job, $fx['craftsman']);
    $forManager = JobPresenter::detail($job, $fx['manager']);

    expect($forCraftsman['cost'])->toBeNull()
        ->and($forCraftsman['labour'])->toBeNull()
        ->and($forCraftsman['materials'])->toBeArray()
        ->and($forManager['cost'])->toBeArray()
        ->and($forManager['labour'])->toBeArray();

    // Workshop user is redirected from /dashboard to /workshop and forbidden from /sales
    $this->actingAs($fx['craftsman'])
        ->get(route('dashboard'))
        ->assertRedirect(route('workshop.board'));

    $this->actingAs($fx['craftsman'])
        ->get(route('workshop.board'))
        ->assertOk();

    $this->actingAs($fx['craftsman'])
        ->get(route('sales-orders.index'))
        ->assertForbidden();
});

