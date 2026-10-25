<?php

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentMethod;
use App\Exceptions\ApprovalRefused;
use App\Mail\PurchaseOrderMail;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReorderService;
use App\Services\Purchasing\SupplierBillService;
use App\Services\Purchasing\SupplierReturnService;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

function createPurchasingVariant(
    string $sku,
    int $price = 15000000,
    bool $serialized = false,
    int $reorderLevel = 0,
    int $reorderQty = 0,
): ProductVariant {
    $cat = Category::query()->firstOrCreate(
        ['slug' => 'purchasing-furniture'],
        ['name' => 'Purchasing Furniture', 'is_active' => true],
    );

    $product = Product::query()->create([
        'category_id' => $cat->id,
        'name' => "Product {$sku}",
        'slug' => strtolower($sku).'-'.uniqid(),
        'type' => ProductType::Stock,
        'is_serialized' => $serialized,
        'is_active' => true,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'barcode' => 'BC-'.$sku,
        'price' => $price,
        'min_price' => $price,
        'average_cost' => 0,
        'last_cost' => 0,
        'reorder_level' => $reorderLevel,
        'reorder_qty' => $reorderQty,
        'is_active' => true,
    ]);
}

test('supplier bank details are encrypted at rest and hidden from serialization', function () {
    $supplier = Supplier::query()->create([
        'name' => 'Ceylon Timber Works',
        'contact_person' => 'Kasun Silva',
        'phone' => '+94112223344',
        'email' => 'kasun@ceylontimber.test',
        'payment_terms_days' => 45,
        'credit_limit' => 100000000,
        'bank_details' => 'Commercial Bank A/C 1002003004',
        'vat_number' => '114477889-7000',
    ]);

    $rawCipher = DB::table('suppliers')->where('id', $supplier->id)->value('bank_details');

    expect($rawCipher)->not->toBe('Commercial Bank A/C 1002003004')
        ->and($supplier->fresh()->bank_details)->toBe('Commercial Bank A/C 1002003004')
        ->and($supplier->toArray())->not->toHaveKey('bank_details');
});

test('average cost maths handles receipt from zero stock, partial receipts across locations, half-up rounding, and serialized units', function () {
    $locA = Location::factory()->create(['code' => 'WHA']);
    $locB = Location::factory()->create(['code' => 'SHB']);
    $admin = User::factory()->admin()->create(['home_location_id' => $locA->id]);
    $supplier = Supplier::query()->create(['name' => 'Teak Supplier']);

    $variant = createPurchasingVariant('SOFA-AVG-01');
    $poService = app(PurchaseOrderService::class);
    $grnService = app(GoodsReceiptService::class);

    // 1. Create & send PO for 10 units at Rs. 10,000.00 (1,000,000 cents)
    $po = $poService->save([
        'supplier_id' => $supplier->id,
        'location_id' => $locA->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'ordered_qty' => Quantity::fromUnits(10),
                'unit_cost' => 1000000,
            ],
        ],
    ], $admin);
    $po = $poService->send($po, $admin, emailSupplier: false);

    // Receive 4 units while stock is at zero -> avg cost = 1,000,000, last_cost = 1,000,000
    $grnService->receive([
        'purchase_order_id' => $po->id,
        'lines' => [
            [
                'purchase_order_line_id' => $po->lines->first()->id,
                'product_variant_id' => $variant->id,
                'received_qty' => Quantity::fromUnits(4),
                'unit_cost' => 1000000,
            ],
        ],
    ], $admin);

    $variant->refresh();
    expect($variant->average_cost)->toBe(1000000)
        ->and($variant->last_cost)->toBe(1000000)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);

    // 2. Receive 6 more units at Location B via direct GRN at Rs. 12,000.00 (1,200,000 cents)
    // Weighted average across all locations: (4 * 1,000,000 + 6 * 1,200,000) / 10 = 1,120,000 cents
    $grnService->receive([
        'supplier_id' => $supplier->id,
        'location_id' => $locB->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'received_qty' => Quantity::fromUnits(6),
                'unit_cost' => 1200000,
            ],
        ],
    ], $admin);

    $variant->refresh();
    expect($variant->average_cost)->toBe(1120000)
        ->and($variant->last_cost)->toBe(1200000);

    // 3. Test half-up integer rounding: 1 unit at 100 cents + 1 unit at 101 cents = 100.5 -> 101 cents
    $roundVariant = createPurchasingVariant('ROUND-AVG-01');
    $grnService->receive([
        'supplier_id' => $supplier->id,
        'location_id' => $locA->id,
        'lines' => [
            ['product_variant_id' => $roundVariant->id, 'received_qty' => Quantity::fromUnits(1), 'unit_cost' => 100],
        ],
    ], $admin);
    $grnService->receive([
        'supplier_id' => $supplier->id,
        'location_id' => $locA->id,
        'lines' => [
            ['product_variant_id' => $roundVariant->id, 'received_qty' => Quantity::fromUnits(1), 'unit_cost' => 101],
        ],
    ], $admin);

    expect($roundVariant->fresh()->average_cost)->toBe(101);

    // 4. Test serialized variant receipt + damaged-on-arrival handling
    $serialVariant = createPurchasingVariant('SERIAL-GRN-01', 20000000, true);
    $grnService->receive([
        'supplier_id' => $supplier->id,
        'location_id' => $locA->id,
        'lines' => [
            [
                'product_variant_id' => $serialVariant->id,
                'received_qty' => Quantity::fromUnits(3),
                'damaged_qty' => Quantity::fromUnits(1),
                'unit_cost' => 8000000,
                'serials' => ['SN-G-01', 'SN-G-02', 'SN-D-03'],
            ],
        ],
    ], $admin);

    $serialVariant->refresh();
    expect($serialVariant->average_cost)->toBe(8000000)
        ->and(StockLevel::query()->where('location_id', $locA->id)->where('product_variant_id', $serialVariant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(2))
        ->and(StockUnit::query()->where('serial_number', 'SN-G-01')->first()->status)->toBe(StockUnitStatus::InStock)
        ->and(StockUnit::query()->where('serial_number', 'SN-D-03')->first()->status)->toBe(StockUnitStatus::Damaged)
        ->and(StockUnit::query()->where('serial_number', 'SN-D-03')->first()->condition)->toBe(StockUnitCondition::Damaged)
        ->and(StockUnit::query()->where('serial_number', 'SN-G-01')->first()->unit_cost)->toBe(8000000);

    $this->artisan('stock:verify')->assertSuccessful();
});

test('purchase order status transitions, back-to-back sales order line link, PDF and queued email work', function () {
    Mail::fake();

    $loc = Location::factory()->create(['code' => 'POX']);
    $admin = User::factory()->admin()->create(['home_location_id' => $loc->id]);
    $this->actingAs($admin);

    $supplier = Supplier::query()->create([
        'name' => 'Nordic Woodworks',
        'email' => 'po@nordicwood.test',
    ]);
    $variant = createPurchasingVariant('BED-NORD-01');

    $poService = app(PurchaseOrderService::class);
    $grnService = app(GoodsReceiptService::class);

    // Create PO with back-to-back sales_order_line_id
    $po = $poService->save([
        'supplier_id' => $supplier->id,
        'location_id' => $loc->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'ordered_qty' => Quantity::fromUnits(5),
                'unit_cost' => 5000000,
                'sales_order_line_id' => 9876,
            ],
        ],
    ], $admin);

    expect($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->lines->first()->sales_order_line_id)->toBe(9876);

    // Send PO -> queues PurchaseOrderMail
    $po = $poService->send($po, $admin, emailSupplier: true);
    expect($po->status)->toBe(PurchaseOrderStatus::Sent);
    Mail::assertQueued(PurchaseOrderMail::class, fn (PurchaseOrderMail $m) => $m->purchaseOrderId === $po->id);

    // Download PDF
    $pdfResponse = $this->get(route('purchasing.orders.pdf', $po));
    $pdfResponse->assertOk();
    expect($pdfResponse->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdfResponse->getContent())->toStartWith('%PDF-1.4');

    // Partial receipt -> partially_received
    $grnService->receive([
        'purchase_order_id' => $po->id,
        'lines' => [
            [
                'purchase_order_line_id' => $po->lines->first()->id,
                'product_variant_id' => $variant->id,
                'received_qty' => Quantity::fromUnits(2),
                'unit_cost' => 5000000,
            ],
        ],
    ], $admin);

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);

    // Cannot cancel once partially received
    expect(fn () => $poService->cancel($po->fresh(), $admin))->toThrow(InvalidArgumentException::class);

    // Remaining receipt -> received
    $grnService->receive([
        'purchase_order_id' => $po->id,
        'lines' => [
            [
                'purchase_order_line_id' => $po->lines->first()->id,
                'product_variant_id' => $variant->id,
                'received_qty' => Quantity::fromUnits(3),
                'unit_cost' => 5000000,
            ],
        ],
    ], $admin);

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Received);

    // Close PO -> closed
    $closed = $poService->close($po->fresh(), $admin, 'All goods received');
    expect($closed->status)->toBe(PurchaseOrderStatus::Closed);
});

test('over-receiving beyond purchase order requires manager PIN approval', function () {
    $loc = Location::factory()->create(['code' => 'OVR']);
    $manager = User::factory()->manager()->create(['home_location_id' => $loc->id, 'pin' => '4321']);
    $manager->assignLocations($loc->id);

    $storekeeper = User::factory()->storekeeper()->create(['home_location_id' => $loc->id]);
    $storekeeper->assignLocations($loc->id);

    $supplier = Supplier::query()->create(['name' => 'OverReceive Supplier']);
    $variant = createPurchasingVariant('CHAIR-OVR-01');

    $poService = app(PurchaseOrderService::class);
    $grnService = app(GoodsReceiptService::class);

    $po = $poService->save([
        'supplier_id' => $supplier->id,
        'location_id' => $loc->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'ordered_qty' => Quantity::fromUnits(3),
                'unit_cost' => 2500000,
            ],
        ],
    ], $manager);
    $po = $poService->send($po, $manager, emailSupplier: false);

    // Storekeeper attempts to receive 4 units (ordered 3) without manager PIN -> ApprovalRefused
    expect(fn () => $grnService->receive([
        'purchase_order_id' => $po->id,
        'lines' => [
            [
                'purchase_order_line_id' => $po->lines->first()->id,
                'product_variant_id' => $variant->id,
                'received_qty' => Quantity::fromUnits(4),
                'unit_cost' => 2500000,
            ],
        ],
    ], $storekeeper))->toThrow(ApprovalRefused::class);

    // Storekeeper provides manager PIN -> succeeds and records approved_by
    $grn = $grnService->receive([
        'purchase_order_id' => $po->id,
        'approver_id' => $manager->id,
        'pin' => '4321',
        'lines' => [
            [
                'purchase_order_line_id' => $po->lines->first()->id,
                'product_variant_id' => $variant->id,
                'received_qty' => Quantity::fromUnits(4),
                'unit_cost' => 2500000,
            ],
        ],
    ], $storekeeper);

    expect($grn->approved_by)->toBe($manager->id)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($po->lines->first()->fresh()->received_qty)->toBe(Quantity::fromUnits(4));
});

test('reorder suggestions group below-reorder variants by preferred supplier and subtract open PO quantities', function () {
    $loc = Location::factory()->create(['code' => 'REO']);
    $admin = User::factory()->admin()->create(['home_location_id' => $loc->id]);
    $supplier = Supplier::query()->create(['name' => 'Preferred Furniture Co']);

    // Reorder level = 5 units, reorder_qty = 8 units
    $variant = createPurchasingVariant('TABLE-REO-01', 12000000, false, Quantity::fromUnits(5), Quantity::fromUnits(8));

    DB::table('product_supplier')->insert([
        'product_variant_id' => $variant->id,
        'supplier_id' => $supplier->id,
        'supplier_sku' => 'SUP-TBL-01',
        'last_cost' => 7000000,
        'is_preferred' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Put 2 units in stock (below reorder level of 5)
    app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $loc->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(2),
        'unit_cost' => 7000000,
    ]);

    $reorderService = app(ReorderService::class);
    $groups = $reorderService->suggestions([$loc->id]);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['supplier_id'])->toBe($supplier->id)
        ->and($groups[0]['items'][0]['available'])->toBe(Quantity::fromUnits(2))
        ->and($groups[0]['items'][0]['suggested_qty'])->toBe(Quantity::fromUnits(8));

    // Create a draft PO from the suggestion -> open_po_qty becomes 8, projected = 10 >= 5
    $po = $reorderService->createPurchaseOrder([
        'supplier_id' => $supplier->id,
        'location_id' => $loc->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'ordered_qty' => Quantity::fromUnits(8),
                'unit_cost' => 7000000,
                'supplier_sku' => 'SUP-TBL-01',
            ],
        ],
    ], $admin);

    expect($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($reorderService->suggestions([$loc->id]))->toBeEmpty();
});

test('supplier returns deduct stock and supplier bills track payment allocations', function () {
    $loc = Location::factory()->create(['code' => 'RET']);
    $admin = User::factory()->admin()->create(['home_location_id' => $loc->id]);
    $supplier = Supplier::query()->create(['name' => 'Billing & Return Supplier', 'payment_terms_days' => 30]);
    $variant = createPurchasingVariant('LAMP-RET-01');

    $grnService = app(GoodsReceiptService::class);
    $returnService = app(SupplierReturnService::class);
    $billService = app(SupplierBillService::class);

    $grn = $grnService->receive([
        'supplier_id' => $supplier->id,
        'location_id' => $loc->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'received_qty' => Quantity::fromUnits(5),
                'unit_cost' => 400000,
            ],
        ],
    ], $admin);

    // Return 1 defective unit to supplier
    $ret = $returnService->create([
        'supplier_id' => $supplier->id,
        'location_id' => $loc->id,
        'goods_receipt_id' => $grn->id,
        'reason' => 'Cracked shade',
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits(1),
                'unit_cost' => 400000,
            ],
        ],
    ], $admin);

    expect($ret->credit_expected)->toBe(400000)
        ->and(StockLevel::query()->where('location_id', $loc->id)->where('product_variant_id', $variant->id)->value('on_hand'))
        ->toBe(Quantity::fromUnits(4));

    // Create supplier bill linked to GRN for Rs. 20,000 (2,000,000 cents)
    $bill = $billService->createBill([
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'INV-9001',
        'subtotal' => 2000000,
        'goods_receipt_ids' => [$grn->id],
    ], $admin);

    expect($bill->status)->toBe(SupplierBillStatus::Unpaid)
        ->and($bill->goodsReceipts)->toHaveCount(1);

    // Partial payment via cheque (Rs. 8,000)
    $billService->recordPayment([
        'supplier_id' => $supplier->id,
        'method' => SupplierPaymentMethod::Cheque,
        'cheque_number' => 'CHQ-778899',
        'cheque_bank' => 'BOC',
        'allocations' => [
            ['supplier_bill_id' => $bill->id, 'amount' => 800000],
        ],
    ], $admin);

    $bill->refresh();
    expect($bill->status)->toBe(SupplierBillStatus::PartiallyPaid)
        ->and($bill->paid_amount)->toBe(800000)
        ->and($bill->balanceDue())->toBe(1200000);

    // Remaining payment via bank transfer (Rs. 12,000)
    $billService->recordPayment([
        'supplier_id' => $supplier->id,
        'method' => SupplierPaymentMethod::BankTransfer,
        'reference' => 'SLIPS-1122',
        'allocations' => [
            ['supplier_bill_id' => $bill->id, 'amount' => 1200000],
        ],
    ], $admin);

    $bill->refresh();
    expect($bill->status)->toBe(SupplierBillStatus::Paid)
        ->and($bill->balanceDue())->toBe(0);

    $this->artisan('stock:verify')->assertSuccessful();
});

test('purchasing permissions and cost visibility rules are enforced across roles', function () {
    $loc = Location::factory()->create(['code' => 'PRM']);
    $supplier = Supplier::query()->create(['name' => 'Permission Supplier']);
    $variant = createPurchasingVariant('DESK-PRM-01');

    $manager = User::factory()->manager()->create(['home_location_id' => $loc->id]);
    $manager->assignLocations($loc->id);

    $storekeeper = User::factory()->storekeeper()->create(['home_location_id' => $loc->id]);
    $storekeeper->assignLocations($loc->id);

    $accountant = User::factory()->accountant()->create(['home_location_id' => $loc->id]);
    $accountant->assignLocations($loc->id);

    $po = app(PurchaseOrderService::class)->save([
        'supplier_id' => $supplier->id,
        'location_id' => $loc->id,
        'lines' => [
            [
                'product_variant_id' => $variant->id,
                'ordered_qty' => Quantity::fromUnits(2),
                'unit_cost' => 6000000,
            ],
        ],
    ], $manager);

    // Storekeeper cannot create POs or view bills, and cost props are omitted from PO index
    $this->actingAs($storekeeper)
        ->get(route('purchasing.orders.create'))
        ->assertForbidden();

    $this->actingAs($storekeeper)
        ->get(route('purchasing.bills.index'))
        ->assertForbidden();

    $this->actingAs($storekeeper)
        ->get(route('purchasing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Purchasing/Index')
            ->where('canViewCost', false)
            ->missing('orders.data.0.total_amount'));

    // Accountant can view bills and sees cost props on PO index
    $this->actingAs($accountant)
        ->get(route('purchasing.bills.index'))
        ->assertOk();

    $this->actingAs($accountant)
        ->get(route('purchasing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Purchasing/Index')
            ->where('canViewCost', true)
            ->where('orders.data.0.total_amount', 12000000));
});
