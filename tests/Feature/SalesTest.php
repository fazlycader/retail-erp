<?php

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\QuotationStatus;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderStatus;
use App\Exceptions\ApprovalRefused;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\SalesOrderService;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupSalesCatalog(): array
{
    $showroom = Location::query()->create([
        'name' => 'Colombo Showroom',
        'code' => 'CMB',
        'type' => 'showroom',
        'is_active' => true,
    ]);

    $warehouse = Location::query()->create([
        'name' => 'Central Warehouse',
        'code' => 'WH',
        'type' => 'warehouse',
        'is_active' => true,
    ]);

    $manager = User::factory()->create([
        'name' => 'Showroom Manager',
        'role' => Role::Manager,
        'pin' => '1234',
        'is_active' => true,
    ]);
    $manager->assignLocations($showroom->id, [$warehouse->id]);

    $cashier = User::factory()->create([
        'name' => 'Showroom Cashier',
        'role' => Role::Cashier,
        'pin' => '5678',
        'is_active' => true,
    ]);
    $cashier->assignLocations($showroom->id);

    $supplier = Supplier::query()->create([
        'name' => 'Teak Master Suppliers',
        'is_active' => true,
    ]);

    $category = Category::query()->create([
        'name' => 'Sofas',
        'slug' => 'sofas',
    ]);

    $productService = app(ProductService::class);
    $product = $productService->save(
        new Product,
        [
            'category_id' => $category->id,
            'name' => 'Verona 3-Seater Sofa',
            'slug' => 'verona-3-seater-sofa',
            'type' => ProductType::Stock,
            'is_active' => true,
        ],
        [],
        [
            [
                'value_ids' => [],
                'sku' => 'SOFA-VER-3S',
                'price' => 10000000, // LKR 100,000.00
                'min_price' => 9200000, // LKR 92,000.00 (8% max floor)
                'online_price' => null,
                'reorder_level' => 0,
                'reorder_qty' => 0,
                'is_active' => true,
                'suppliers' => [
                    [
                        'supplier_id' => $supplier->id,
                        'supplier_sku' => 'TM-VER-3S',
                        'last_cost' => 6800000,
                        'is_preferred' => true,
                    ],
                ],
            ],
        ],
    );

    /** @var ProductVariant $variant */
    $variant = $product->variants()->first();

    // Seed 5 units at showroom
    app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $showroom->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(5),
        'unit_cost' => 6800000,
        'user_id' => $manager->id,
    ]);

    $customer = Customer::query()->create([
        'name' => 'Roshan Silva',
        'phone' => '+94771234567',
        'email' => 'roshan@example.com',
    ]);

    return compact('showroom', 'warehouse', 'manager', 'cashier', 'supplier', 'variant', 'customer');
}

test('customer creation normalizes Sri Lankan phone and supports lookup by phone', function () {
    ['manager' => $manager] = setupSalesCatalog();

    $this->actingAs($manager)
        ->postJson(route('customers.store'), [
            'name' => 'Anura Kumara',
            'phone' => '071 222 3344',
            'nic' => '911234567V',
            'addresses' => [
                [
                    'label' => 'Home',
                    'line1' => '12 Flower Road',
                    'district' => 'Colombo',
                    'maps_url' => 'https://maps.google.com/?q=6.9,79.8',
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('customer.phone', '+94712223344');

    $this->actingAs($manager)
        ->getJson(route('customers.lookup', ['q' => '0712223344']))
        ->assertOk()
        ->assertJsonCount(1, 'customers')
        ->assertJsonPath('customers.0.name', 'Anura Kumara');
});

test('confirming a sales order assigns invoice number, reserves stock and creates back-to-back PO draft', function () {
    [
        'showroom' => $showroom,
        'manager' => $manager,
        'variant' => $variant,
        'customer' => $customer,
    ] = setupSalesCatalog();

    $settings = CompanySetting::current();
    $settings->update(['require_advance_bp' => 2000]); // 20% advance required
    CompanySetting::forget();

    $salesOrders = app(SalesOrderService::class);

    $order = $salesOrders->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'source' => SalesLineSource::Stock->value,
                'fulfil_location_id' => $showroom->id,
                'quantity' => Quantity::fromUnits(2),
                'unit_price' => 10000000,
            ],
            [
                'product_variant_id' => $variant->id,
                'source' => SalesLineSource::SupplierOrder->value,
                'fulfil_location_id' => $showroom->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 10000000,
            ],
        ],
    ], $manager);

    $confirmed = $salesOrders->confirm($order, $manager);

    expect($confirmed->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($confirmed->invoice_number)->toBe('INV-CMB-000001')
        ->and($confirmed->advance_pending)->toBeTrue()
        ->and($confirmed->advance_required_cents)->toBe(6000000);

    // Stock line reserved 2 units at showroom
    $level = StockLevel::query()
        ->where('product_variant_id', $variant->id)
        ->where('location_id', $showroom->id)
        ->firstOrFail();

    expect($level->on_hand)->toBe(Quantity::fromUnits(5))
        ->and($level->reserved)->toBe(Quantity::fromUnits(2));

    // Back-to-back PO draft created for the supplier_order line
    $supplierOrderLine = $confirmed->lines->where('source', SalesLineSource::SupplierOrder)->first();
    expect($supplierOrderLine->purchase_order_line_id)->not->toBeNull();

    $po = PurchaseOrder::query()->first();
    expect($po)->not->toBeNull()
        ->and($po->lines->first()->sales_order_line_id)->toBe($supplierOrderLine->id)
        ->and($po->lines->first()->ordered_qty)->toBe(Quantity::fromUnits(1));
});

test('partial and full fulfilment issues stock from reservation and updates order status', function () {
    [
        'showroom' => $showroom,
        'manager' => $manager,
        'variant' => $variant,
        'customer' => $customer,
    ] = setupSalesCatalog();

    $salesOrders = app(SalesOrderService::class);

    $order = $salesOrders->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'source' => SalesLineSource::Stock->value,
                'fulfil_location_id' => $showroom->id,
                'quantity' => Quantity::fromUnits(3),
                'unit_price' => 10000000,
            ],
        ],
    ], $manager);

    $order = $salesOrders->confirm($order, $manager);
    $lineId = $order->lines->first()->id;

    // Partial fulfilment: 1 of 3 units
    $order = $salesOrders->fulfil($order, [$lineId => Quantity::fromUnits(1)], $manager, 'First batch');
    expect($order->status)->toBe(SalesOrderStatus::PartiallyFulfilled)
        ->and($order->lines->first()->fulfilled_qty)->toBe(Quantity::fromUnits(1))
        ->and($order->lines->first()->reserved_qty)->toBe(Quantity::fromUnits(2));

    $level = StockLevel::query()
        ->where('product_variant_id', $variant->id)
        ->where('location_id', $showroom->id)
        ->firstOrFail();
    expect($level->on_hand)->toBe(Quantity::fromUnits(4))
        ->and($level->reserved)->toBe(Quantity::fromUnits(2));

    // Remaining fulfilment: 2 of 3 units
    $order = $salesOrders->fulfil($order, [$lineId => Quantity::fromUnits(2)], $manager, 'Final batch');
    expect($order->status)->toBe(SalesOrderStatus::Fulfilled)
        ->and($order->lines->first()->fulfilled_qty)->toBe(Quantity::fromUnits(3))
        ->and($order->lines->first()->reserved_qty)->toBe(0);

    $level->refresh();
    expect($level->on_hand)->toBe(Quantity::fromUnits(2))
        ->and($level->reserved)->toBe(0);

    // Delivery note PDF renders
    $this->actingAs($manager)
        ->get(route('sales.orders.delivery-note', $order))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('cancelling a confirmed sales order releases stock reservations and requires manager if paid', function () {
    [
        'showroom' => $showroom,
        'manager' => $manager,
        'cashier' => $cashier,
        'variant' => $variant,
        'customer' => $customer,
    ] = setupSalesCatalog();

    $salesOrders = app(SalesOrderService::class);

    $order = $salesOrders->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'source' => SalesLineSource::Stock->value,
                'fulfil_location_id' => $showroom->id,
                'quantity' => Quantity::fromUnits(2),
                'unit_price' => 10000000,
            ],
        ],
    ], $cashier);

    $order = $salesOrders->confirm($order, $cashier);

    // Simulate an advance payment recorded on the order
    $order->update(['paid_amount' => 1000000]);

    // Cashier cannot cancel a paid order without manager PIN
    expect(fn () => $salesOrders->cancel($order, $cashier, 'Customer changed mind'))
        ->toThrow(ApprovalRefused::class);

    // With manager PIN, cancellation succeeds and releases reservations
    $cancelled = $salesOrders->cancel($order, $cashier, 'Customer changed mind', $manager->id, '1234');
    expect($cancelled->status)->toBe(SalesOrderStatus::Cancelled);

    $level = StockLevel::query()
        ->where('product_variant_id', $variant->id)
        ->where('location_id', $showroom->id)
        ->firstOrFail();

    expect($level->reserved)->toBe(0)
        ->and($cancelled->lines->first()->reservations->first()->status)->toBe(ReservationStatus::Released);
});

test('discount and min_price rules enforce role limits and manager PIN approval', function () {
    [
        'showroom' => $showroom,
        'manager' => $manager,
        'cashier' => $cashier,
        'variant' => $variant,
        'customer' => $customer,
    ] = setupSalesCatalog();

    $salesOrders = app(SalesOrderService::class);

    // Cashier max discount is 5% (500 bp). 6% discount (600 bp) without manager PIN must fail:
    expect(fn () => $salesOrders->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 10000000,
                'discount_type' => 'percent',
                'discount_value' => 600,
            ],
        ],
    ], $cashier))->toThrow(ApprovalRefused::class);

    // Selling below min_price (92,000.00) without manager PIN must fail:
    expect(fn () => $salesOrders->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 9000000,
            ],
        ],
    ], $cashier))->toThrow(ApprovalRefused::class);

    // With valid manager PIN, selling below min_price succeeds and records discount_approved_by:
    $order = $salesOrders->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'approver_id' => $manager->id,
        'pin' => '1234',
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 9000000,
            ],
        ],
    ], $cashier);

    expect($order->discount_approved_by)->toBe($manager->id)
        ->and($order->grand_total)->toBe(9000000);
});

test('quotation conversion detects catalog price changes and supports honouring or repricing', function () {
    [
        'showroom' => $showroom,
        'manager' => $manager,
        'variant' => $variant,
        'customer' => $customer,
    ] = setupSalesCatalog();

    $quotations = app(QuotationService::class);

    $quote = $quotations->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 10000000,
            ],
        ],
    ], $manager);

    // Catalog price increases from LKR 100,000 to LKR 115,000
    $variant->update(['price' => 11500000]);

    $preview = $quotations->previewConversion($quote);
    expect($preview['any_price_changed'])->toBeTrue()
        ->and($preview['lines'][0]['quoted_unit_price'])->toBe(10000000)
        ->and($preview['lines'][0]['current_unit_price'])->toBe(11500000)
        ->and($preview['lines'][0]['difference'])->toBe(1500000);

    // Convert honouring the quoted price (LKR 100,000)
    $orderHonoured = $quotations->convertToSalesOrder($quote, $manager, honourQuotedPrices: true);
    expect($orderHonoured->grand_total)->toBe(10000000)
        ->and($quote->fresh()->status)->toBe(QuotationStatus::Converted);

    // Create a second quote and convert using current catalog price (LKR 115,000)
    $quote2 = $quotations->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 10000000,
            ],
        ],
    ], $manager);

    $orderRepriced = $quotations->convertToSalesOrder($quote2, $manager, honourQuotedPrices: false);
    expect($orderRepriced->grand_total)->toBe(11500000);
});

test('customers, quotations and sales orders Inertia screens and PDFs render cleanly', function () {
    [
        'showroom' => $showroom,
        'manager' => $manager,
        'variant' => $variant,
        'customer' => $customer,
    ] = setupSalesCatalog();

    $quotations = app(QuotationService::class);
    $salesOrders = app(SalesOrderService::class);

    $quote = $quotations->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'follow_up_date' => now()->toDateString(),
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 10000000,
            ],
        ],
    ], $manager);

    $order = $salesOrders->save([
        'customer_id' => $customer->id,
        'location_id' => $showroom->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_price' => 10000000,
            ],
        ],
    ], $manager);

    $this->actingAs($manager)->get(route('customers.index'))->assertOk();
    $this->actingAs($manager)->get(route('customers.show', $customer))->assertOk();
    $this->actingAs($manager)->get(route('sales.quotations.index'))->assertOk();
    $this->actingAs($manager)->get(route('sales.quotations.show', $quote))->assertOk();
    $this->actingAs($manager)->get(route('sales.index'))->assertOk();
    $this->actingAs($manager)->get(route('sales.orders.show', $order))->assertOk();

    // Public quotation PDF link used by WhatsApp wa.me share
    $this->get(route('quotations.public-pdf', $quote->share_token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
