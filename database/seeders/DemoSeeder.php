<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Enums\FulfilmentType;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\ProductType;
use App\Enums\ReturnCondition;
use App\Enums\ReturnOutcome;
use App\Enums\Role;
use App\Enums\SalesChannel;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderKind;
use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\ProductionStage;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Register;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Cash\ShiftService;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\TransferService;
use App\Services\Production\CustomSpecService;
use App\Services\Production\ProductionService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\SupplierBillService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\PosCheckoutService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\ReturnService;
use App\Services\Sales\SalesOrderService;
use App\Support\Quantity;
use Illuminate\Database\Seeder;

/**
 * Sample data for demos and local testing (php artisan demo:reset). Each build step adds its
 * own demo data here. Never runs in production. Every demo login: password "password", PIN 1234.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Demo data is not allowed in production.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        CompanySetting::current()->fill([
            'name' => 'Lanka Home Centre (Pvt) Ltd',
            'address' => '123 Galle Road, Colombo 03',
            'phone' => '+94112345678',
            'email' => 'info@example.com',
            'vat_registered' => true,
            'vat_number' => '123456789-7000',
        ])->save();

        $warehouse = Location::query()->where('code', 'HO')->firstOrFail();
        $showroom = Location::query()->where('code', 'MS')->firstOrFail();
        $kandy = Location::query()->firstOrCreate(['code' => 'KDY'], ['name' => 'Kandy Showroom', 'type' => LocationType::Showroom, 'address' => '45 Peradeniya Road, Kandy']);
        $workshop = Location::query()->firstOrCreate(['code' => 'MW'], ['name' => 'Moratuwa Workshop', 'type' => LocationType::Workshop, 'address' => '112 Laxapathiya Road, Moratuwa']);

        ProductionStage::forWorkshop($workshop->id);

        CompanySetting::current()->fill([
            'workshop_location_id' => $workshop->id,
            'workshop_overhead_bp' => 1500,
            'workshop_lead_days' => 21,
        ])->save();

        User::query()->where('email', 'admin@example.com')->update(['pin' => bcrypt('1234')]);

        $staff = [
            ['Nimal Perera', 'manager@example.com', Role::Manager, $showroom, [$kandy, $workshop]],
            ['Sunil Fernando', 'warehouse.manager@example.com', Role::Manager, $warehouse, [$workshop]],
            ['Kamala Silva', 'cashier@example.com', Role::Cashier, $showroom, []],
            ['Ruwan Jayasinghe', 'kandy.cashier@example.com', Role::Cashier, $kandy, []],
            ['Ajith Bandara', 'storekeeper@example.com', Role::Storekeeper, $warehouse, [$showroom, $workshop]],
            ['Dilani Wickramasinghe', 'accountant@example.com', Role::Accountant, $warehouse, [$showroom, $kandy]],
            ['Pradeep Mendis', 'workshop@example.com', Role::Workshop, $workshop, []],
        ];

        foreach ($staff as [$name, $email, $role, $home, $extra]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $name, 'role' => $role, 'password' => 'password', 'pin' => '1234', 'email_verified_at' => now(), 'is_active' => true])->save();
            $user->assignLocations($home->id, array_map(fn (Location $l) => $l->id, $extra));
        }

        $this->seedCatalog();
        $this->seedInventory($warehouse, $showroom, $kandy, $workshop);
        $this->seedPurchasing($warehouse);
        $this->call(DemoHistorySeeder::class); // the last N days (demo:reset --days=N), before today's examples
        $this->seedSales($showroom, $warehouse, $workshop);
    }

    private function seedSales(Location $showroom, Location $warehouse, Location $workshop): void
    {
        if (Customer::query()->where('phone', '+94771234567')->exists()) {
            return;
        }

        $manager = User::query()->where('email', 'manager@example.com')->first()
            ?? User::query()->where('email', 'admin@example.com')->first();
        $variants = ProductVariant::query()->pluck('id', 'sku');

        if (! $manager || ! isset($variants['SOFA-ROMA-GRY-2S'], $variants['DIN-GALLE-6S'])) {
            return;
        }

        $customer1 = Customer::query()->create([
            'name' => 'Chaminda Rajapaksa',
            'phone' => '+94771234567',
            'secondary_phone' => '+94112554433',
            'email' => 'chaminda@example.com',
            'nic' => '881234567V',
            'type' => CustomerType::Individual,
            'addresses' => [
                [
                    'label' => 'Home',
                    'line1' => '18 Barnes Place',
                    'line2' => 'Cinnamon Gardens',
                    'city' => 'Colombo 07',
                    'district' => 'Colombo',
                    'maps_url' => 'https://maps.google.com/?q=6.9114,79.8686',
                    'is_default' => true,
                ],
            ],
            'credit_limit' => 50000000,
        ]);

        $customer2 = Customer::query()->create([
            'name' => 'Serendib Boutique Villas (Pvt) Ltd',
            'phone' => '+94719876543',
            'email' => 'procurement@serendibvillas.example.com',
            'type' => CustomerType::Business,
            'vat_number' => '177889900-7000',
            'addresses' => [
                [
                    'label' => 'Head Office',
                    'line1' => '210 Galle Face Terrace',
                    'city' => 'Colombo 03',
                    'district' => 'Colombo',
                    'is_default' => true,
                ],
            ],
            'credit_limit' => 200000000,
        ]);

        $quotationService = app(QuotationService::class);
        $salesOrderService = app(SalesOrderService::class);

        $quotation = $quotationService->save([
            'customer_id' => $customer2->id,
            'location_id' => $showroom->id,
            'valid_until' => now()->addDays(14)->toDateString(),
            'follow_up_date' => now()->toDateString(),
            'notes' => 'Hospitality villa furnishing quote — includes custom teak bench',
            'lines' => [
                [
                    'product_variant_id' => $variants['DIN-GALLE-6S'],
                    'quantity' => Quantity::fromUnits(2),
                    'unit_price' => 16500000,
                ],
                [
                    'is_custom' => true,
                    'name_snapshot' => 'Custom 2.4m Solid Teak Verandah Bench',
                    'custom_spec' => '2400×550×450mm, Natural Oil Finish',
                    'quantity' => Quantity::fromUnits(2),
                    'unit_price' => 6500000,
                ],
            ],
        ], $manager);

        $quotationService->send($quotation, $manager, emailCustomer: false);

        $order = $salesOrderService->save([
            'customer_id' => $customer1->id,
            'location_id' => $showroom->id,
            'channel' => SalesChannel::Showroom->value,
            'kind' => SalesOrderKind::Order->value,
            'fulfilment_type' => FulfilmentType::Deliver->value,
            'delivery_address' => $customer1->defaultAddress(),
            'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'delivery_charge' => 450000,
            'notes' => 'Call 30 mins before arrival',
            'lines' => [
                [
                    'product_variant_id' => $variants['SOFA-ROMA-GRY-2S'],
                    'source' => SalesLineSource::Stock->value,
                    'fulfil_location_id' => $showroom->id,
                    'quantity' => Quantity::fromUnits(1),
                    'unit_price' => 14500000,
                ],
            ],
        ], $manager);

        $order = $salesOrderService->confirm($order, $manager);

        $this->seedPayments($showroom, $order, $customer1, $manager);
        $this->seedWorkshopOrder($showroom, $workshop, $customer2, $manager);
    }

    private function seedWorkshopOrder(Location $showroom, Location $workshop, Customer $customer, User $manager): void
    {
        if (ProductionJob::query()->exists()) {
            return;
        }

        $variants = ProductVariant::query()->pluck('id', 'sku');
        $craftsman = User::query()->where('email', 'workshop@example.com')->first() ?? $manager;
        $specService = app(CustomSpecService::class);
        $salesOrderService = app(SalesOrderService::class);
        $productionService = app(ProductionService::class);

        $spec = $specService->save(null, [
            'base_product_id' => Product::query()->where('slug', 'kandyan-solid-teak-bed-frame')->value('id'),
            'width_mm' => 2000,
            'depth_mm' => 2150,
            'height_mm' => 1250,
            'material' => 'Seasoned Burma Teak',
            'fabric_code' => 'VEL-EMR-04',
            'colour_code' => 'Emerald Green headboard panel',
            'finish' => 'Satin Matt Natural Teak',
            'lead_time_days' => 18,
            'notes' => 'Extended king headboard with recessed brass reading light cutouts.',
        ]);
        $specService->recordApproval($spec, 'Ms. Anushka Jayawardena (GM)', today()->subDays(2), null, $manager);

        $customOrder = $salesOrderService->save([
            'customer_id' => $customer->id,
            'location_id' => $showroom->id,
            'channel' => SalesChannel::Showroom->value,
            'kind' => SalesOrderKind::Order->value,
            'fulfilment_type' => FulfilmentType::Deliver->value,
            'delivery_address' => $customer->defaultAddress(),
            'requested_delivery_date' => now()->addDays(16)->toDateString(),
            'notes' => 'Bespoke suite master bed order',
            'lines' => [
                [
                    'is_custom' => true,
                    'name_snapshot' => 'Bespoke Kandyan Extended King Bed Frame',
                    'custom_spec' => $spec->summary(),
                    'custom_spec_id' => $spec->id,
                    'source' => SalesLineSource::Make->value,
                    'fulfil_location_id' => $showroom->id,
                    'quantity' => Quantity::fromUnits(1),
                    'unit_price' => 29500000,
                ],
            ],
        ], $manager);

        $salesOrderService->confirm($customOrder, $manager);

        $job = ProductionJob::query()->first();
        if (! $job) {
            return;
        }

        $productionService->update($job, ['assigned_to' => $craftsman->id], $manager);

        $firstStage = ProductionStage::forWorkshop($workshop->id)->first();
        if ($firstStage) {
            $productionService->moveToStage($job, $firstStage, $craftsman, 'Timber selected and planed to thickness.');
        }

        if (isset($variants['MAT-TEAK-SQFT'])) {
            $teak = ProductVariant::query()->find($variants['MAT-TEAK-SQFT']);
            if ($teak) {
                $productionService->issueMaterial($job, $teak, 42500, $craftsman, 'Headboard & side rails');
            }
        }

        if (isset($variants['MAT-VEL-EMR'])) {
            $velvet = ProductVariant::query()->find($variants['MAT-VEL-EMR']);
            if ($velvet) {
                $productionService->issueMaterial($job, $velvet, 3500, $craftsman, 'Headboard upholstery panel');
            }
        }

        $productionService->addLabour($job, [
            'user_id' => $craftsman->id,
            'worker_name' => $craftsman->name,
            'work_date' => today()->toDateString(),
            'minutes' => 360,
            'hourly_rate' => 120000,
            'note' => 'Cutting and mortise-and-tenon joinery',
        ], $manager);
    }

    /**
     * Step 6: an advance on the delivery order, a walk-in cash & carry sale split across card and
     * cash (returned onto a credit note), and a sale paid by a pending cheque.
     */
    private function seedPayments(Location $showroom, SalesOrder $order, Customer $customer, User $manager): void
    {
        $cashier = User::query()->where('email', 'cashier@example.com')->first() ?? $manager;
        $method = fn (PaymentMethodCode $code) => PaymentMethod::byCode($code)->id;
        $payments = app(PaymentService::class);

        // Payments are taken during a shift: the cashier's is open on the showroom's Main Counter.
        $register = Register::query()->where('location_id', $showroom->id)->firstOrFail();
        app(ShiftService::class)->openFor($cashier) ?? app(ShiftService::class)->open($register, $cashier, ['500000' => 1, '100000' => 4, '10000' => 10]);

        $payments->receive($order, ['payment_method_id' => $method(PaymentMethodCode::BankTransfer), 'amount' => 5000000, 'reference' => 'BOC-TRX-558201'], $cashier);

        $inStock = StockLevel::query()
            ->where('location_id', $showroom->id)
            ->whereRaw('on_hand - reserved >= ?', [Quantity::fromUnits(2)])
            ->whereHas('variant.product', fn ($q) => $q->where('is_serialized', false))
            ->with('variant')
            ->first()?->variant;

        if (! $inStock) {
            return;
        }

        $line = fn (int $units) => [
            'product_variant_id' => $inStock->id,
            'source' => SalesLineSource::Stock->value,
            'fulfil_location_id' => $showroom->id,
            'quantity' => Quantity::fromUnits($units),
            'unit_price' => $inStock->price,
        ];
        $pos = app(PosCheckoutService::class);

        $card = intdiv($inStock->price, 2);
        $walkIn = $pos->checkout(['location_id' => $showroom->id, 'kind' => SalesOrderKind::CashAndCarry->value, 'lines' => [$line(1)]], [
            ['payment_method_id' => $method(PaymentMethodCode::Card), 'amount' => $card, 'reference' => 'APPR 448812'],
            ['payment_method_id' => $method(PaymentMethodCode::Cash), 'tendered' => (int) (ceil(($inStock->price - $card) / 500000) * 500000)],
        ], $cashier);

        $pos->checkout(['customer_id' => $customer->id, 'location_id' => $showroom->id, 'kind' => SalesOrderKind::CashAndCarry->value, 'lines' => [$line(1)]], [[
            'payment_method_id' => $method(PaymentMethodCode::Cheque),
            'amount' => $inStock->price,
            'cheque_bank' => 'Commercial Bank',
            'cheque_branch' => 'Kollupitiya',
            'cheque_number' => '004512',
            'cheque_date' => today()->addDays(2)->toDateString(),
        ]], $cashier);

        app(ReturnService::class)->process($walkIn, [[
            'sales_order_line_id' => $walkIn->lines->first()->id,
            'quantity' => Quantity::fromUnits(1),
            'condition' => ReturnCondition::Display,
        ]], ReturnOutcome::CreditNote, 'Colour did not match the room', $cashier);
    }

    private function seedPurchasing(Location $warehouse): void
    {
        $damro = Supplier::query()->firstOrCreate(['name' => 'Damro Manufacturing']);
        $damro->fill([
            'contact_person' => 'Rohan Wijesinghe',
            'phone' => '+94112855000',
            'secondary_phone' => '+94777123456',
            'email' => 'orders@damro.example.com',
            'address' => '45 High Level Road, Maharagama',
            'payment_terms_days' => 45,
            'credit_limit' => 500000000,
            'bank_details' => 'Commercial Bank — Maharagama Branch — A/C 1080045219',
            'vat_number' => '114258963-7000',
            'is_active' => true,
        ])->save();

        $teakHouse = Supplier::query()->firstOrCreate(['name' => 'Moratuwa Teak Craft']);
        $teakHouse->fill([
            'contact_person' => 'Lalith Peiris',
            'phone' => '+94112645123',
            'email' => 'sales@teakcraft.example.com',
            'address' => '88 Galle Road, Moratuwa',
            'payment_terms_days' => 30,
            'credit_limit' => 250000000,
            'bank_details' => 'Sampath Bank — Moratuwa Branch — A/C 001910004421',
            'vat_number' => '119874563-7000',
            'is_active' => true,
        ])->save();

        if (PurchaseOrder::query()->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@example.com')->first();
        $variants = ProductVariant::query()->pluck('id', 'sku');

        if (! $admin || ! isset($variants['SOFA-ROMA-GRY-2S'], $variants['DIN-GALLE-6S'])) {
            return;
        }

        $poService = app(PurchaseOrderService::class);
        $grnService = app(GoodsReceiptService::class);
        $billService = app(SupplierBillService::class);

        $po = $poService->save([
            'supplier_id' => $damro->id,
            'location_id' => $warehouse->id,
            'order_date' => now()->subDays(5)->toDateString(),
            'expected_date' => now()->addDays(2)->toDateString(),
            'notes' => 'Monthly sofa restock order',
            'lines' => [
                [
                    'product_variant_id' => $variants['SOFA-ROMA-GRY-2S'],
                    'supplier_sku' => 'DMR-ROMA-2S',
                    'ordered_qty' => Quantity::fromUnits(4),
                    'unit_cost' => 9800000,
                    'discount_cents' => 0,
                    'tax_rate_bp' => 0,
                ],
            ],
        ], $admin);

        $poService->send($po, $admin, emailSupplier: false);

        $grn = $grnService->receive([
            'purchase_order_id' => $po->id,
            'supplier_delivery_note' => 'DN-DMR-9901',
            'lines' => [
                [
                    'purchase_order_line_id' => $po->lines->first()->id,
                    'product_variant_id' => $variants['SOFA-ROMA-GRY-2S'],
                    'received_qty' => Quantity::fromUnits(2),
                    'damaged_qty' => 0,
                    'unit_cost' => 9800000,
                ],
            ],
        ], $admin);

        $billService->createBill([
            'supplier_id' => $damro->id,
            'supplier_invoice_number' => 'INV-DMR-2026-441',
            'bill_date' => now()->subDays(2)->toDateString(),
            'subtotal' => 19600000,
            'tax_total' => 0,
            'total_amount' => 19600000,
            'goods_receipt_ids' => [$grn->id],
            'notes' => 'Partial delivery invoice against '.$po->number,
        ], $admin);
    }

    private function seedInventory(Location $warehouse, Location $showroom, Location $kandy, Location $workshop): void
    {
        if (StockMovement::query()->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@example.com')->first();
        $stock = app(StockService::class);
        $transfers = app(TransferService::class);

        $variants = ProductVariant::query()->pluck('id', 'sku');

        $opening = [
            ['SOFA-ROMA-GRY-2S', $warehouse->id, 10, 9800000],
            ['SOFA-ROMA-GRY-2S', $showroom->id, 3, 9800000],
            ['SOFA-ROMA-GRY-2S', $kandy->id, 1, 9800000],
            ['SOFA-ROMA-GRY-3S', $warehouse->id, 8, 12500000],
            ['SOFA-ROMA-GRY-3S', $showroom->id, 2, 12500000],
            ['SOFA-ROMA-BGE-2S', $warehouse->id, 6, 9800000],
            ['SOFA-ROMA-BGE-3S', $warehouse->id, 5, 12500000],
            ['DIN-GALLE-6S', $warehouse->id, 4, 11000000],
            ['DIN-GALLE-6S', $showroom->id, 1, 11000000],
            ['MAT-TEAK-SQFT', $workshop->id, 250, 220000],
            ['MAT-VEL-EMR', $workshop->id, 60, 380000],
            ['MAT-FOAM-HD', $workshop->id, 40, 650000],
        ];

        $entries = [];
        foreach ($opening as [$sku, $locId, $units, $costCents]) {
            if (! isset($variants[$sku])) {
                continue;
            }
            ProductVariant::query()->whereKey($variants[$sku])->update([
                'average_cost' => $costCents,
                'last_cost' => $costCents,
            ]);
            $entries[] = [
                'product_variant_id' => $variants[$sku],
                'location_id' => $locId,
                'type' => MovementType::Opening,
                'quantity' => Quantity::fromUnits($units),
                'unit_cost' => $costCents,
                'user_id' => $admin?->id,
                'note' => 'Opening stock',
            ];
        }

        if (isset($variants['REC-OSLO-01'])) {
            $reclinerId = $variants['REC-OSLO-01'];
            foreach ([
                ['SN-OSLO-0001', $warehouse->id, StockUnitCondition::New],
                ['SN-OSLO-0002', $warehouse->id, StockUnitCondition::New],
                ['SN-OSLO-0003', $showroom->id, StockUnitCondition::Display],
            ] as [$serial, $locId, $condition]) {
                $unit = StockUnit::query()->create([
                    'serial_number' => $serial,
                    'product_variant_id' => $reclinerId,
                    'location_id' => $locId,
                    'status' => StockUnitStatus::InStock,
                    'condition' => $condition,
                    'unit_cost' => 8800000,
                    'received_at' => now(),
                    'note' => $condition === StockUnitCondition::Display ? 'Showroom floor display unit' : 'Opening stock',
                ]);

                $entries[] = [
                    'product_variant_id' => $reclinerId,
                    'location_id' => $locId,
                    'type' => MovementType::Opening,
                    'quantity' => Quantity::fromUnits(1),
                    'unit_cost' => 8800000,
                    'stock_unit_id' => $unit->id,
                    'user_id' => $admin?->id,
                    'note' => "Opening stock ({$serial})",
                ];
            }
        }

        $stock->moveMany($entries);

        if ($admin && isset($variants['SOFA-ROMA-GRY-3S'])) {
            $trf = $transfers->create([
                'from_location_id' => $warehouse->id,
                'to_location_id' => $showroom->id,
                'vehicle_note' => 'Lorry WP LB-4521 / Driver Kamal',
                'note' => 'Weekly showroom replenishment',
                'lines' => [
                    [
                        'product_variant_id' => $variants['SOFA-ROMA-GRY-3S'],
                        'requested_qty' => Quantity::fromUnits(2),
                    ],
                ],
            ], $admin);

            $transfers->dispatch($trf, $admin);
        }
    }

    private function seedCatalog(): void
    {
        if (Product::query()->exists()) {
            return;
        }

        $living = Category::create(['name' => 'Living Room', 'slug' => 'living-room', 'sort_order' => 0, 'default_warranty_months' => 24, 'commission_group' => 'living']);
        $sofas = Category::create(['parent_id' => $living->id, 'name' => 'Sofas', 'slug' => 'sofas', 'sort_order' => 0, 'default_warranty_months' => 36]);
        $recliners = Category::create(['parent_id' => $sofas->id, 'name' => 'Recliners', 'slug' => 'recliners', 'sort_order' => 0]);

        $bedroom = Category::create(['name' => 'Bedroom', 'slug' => 'bedroom', 'sort_order' => 1, 'default_warranty_months' => 60, 'commission_group' => 'bedroom']);
        $beds = Category::create(['parent_id' => $bedroom->id, 'name' => 'Beds', 'slug' => 'beds', 'sort_order' => 0]);

        $dining = Category::create(['name' => 'Dining', 'slug' => 'dining', 'sort_order' => 2, 'default_warranty_months' => 24, 'commission_group' => 'dining']);
        $services = Category::create(['name' => 'Services', 'slug' => 'services', 'sort_order' => 3]);

        $colour = Attribute::create(['name' => 'Colour', 'sort_order' => 0]);
        $grey = AttributeValue::create(['attribute_id' => $colour->id, 'value' => 'Charcoal Grey', 'code' => 'GRY', 'swatch_color' => '#4B5563', 'sort_order' => 0]);
        $beige = AttributeValue::create(['attribute_id' => $colour->id, 'value' => 'Sand Beige', 'code' => 'BGE', 'swatch_color' => '#D6C7B2', 'sort_order' => 1]);

        $size = Attribute::create(['name' => 'Size', 'sort_order' => 1]);
        $twoSeater = AttributeValue::create(['attribute_id' => $size->id, 'value' => '2 Seater', 'code' => '2S', 'sort_order' => 0]);
        $threeSeater = AttributeValue::create(['attribute_id' => $size->id, 'value' => '3 Seater', 'code' => '3S', 'sort_order' => 1]);
        $queen = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'Queen (60×78")', 'code' => 'QN', 'sort_order' => 2]);
        $king = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'King (72×78")', 'code' => 'KG', 'sort_order' => 3]);

        $finish = Attribute::create(['name' => 'Finish', 'sort_order' => 2]);
        $naturalTeak = AttributeValue::create(['attribute_id' => $finish->id, 'value' => 'Natural Teak', 'code' => 'NTK', 'swatch_color' => '#A47148', 'sort_order' => 0]);
        $walnut = AttributeValue::create(['attribute_id' => $finish->id, 'value' => 'Dark Walnut', 'code' => 'WAL', 'swatch_color' => '#5C4033', 'sort_order' => 1]);

        $damro = Supplier::create(['name' => 'Damro Manufacturing']);
        $teakHouse = Supplier::create(['name' => 'Moratuwa Teak Craft']);

        $products = app(ProductService::class);

        $products->save(
            new Product,
            [
                'category_id' => $sofas->id,
                'name' => 'Roma Fabric Sofa',
                'slug' => 'roma-fabric-sofa',
                'brand' => 'Damro',
                'description' => '<p>Kiln-dried hardwood frame with pocket-spring cushions and stain-resistant woven fabric.</p>',
                'type' => ProductType::Stock,
                'width_mm' => 2100,
                'depth_mm' => 900,
                'height_mm' => 860,
                'weight_g' => 54000,
                'assembly_required' => false,
                'requires_delivery' => true,
                'publish_online' => true,
                'is_active' => true,
                'tags' => ['living room', 'fabric'],
            ],
            [$colour->id, $size->id],
            [
                ['value_ids' => [$grey->id, $twoSeater->id], 'sku' => 'SOFA-ROMA-GRY-2S', 'price' => 14500000, 'min_price' => 13200000, 'online_price' => null, 'reorder_level' => 2000, 'reorder_qty' => 4000, 'is_active' => true, 'suppliers' => [['supplier_id' => $damro->id, 'supplier_sku' => 'DM-RM2-GR', 'last_cost' => 9800000, 'is_preferred' => true]]],
                ['value_ids' => [$grey->id, $threeSeater->id], 'sku' => 'SOFA-ROMA-GRY-3S', 'price' => 18500000, 'min_price' => 17000000, 'online_price' => 17900000, 'reorder_level' => 2000, 'reorder_qty' => 4000, 'is_active' => true, 'suppliers' => [['supplier_id' => $damro->id, 'supplier_sku' => 'DM-RM3-GR', 'last_cost' => 12500000, 'is_preferred' => true]]],
                ['value_ids' => [$beige->id, $twoSeater->id], 'sku' => 'SOFA-ROMA-BGE-2S', 'price' => 14500000, 'min_price' => 13200000, 'online_price' => null, 'reorder_level' => 1000, 'reorder_qty' => 2000, 'is_active' => true],
                ['value_ids' => [$beige->id, $threeSeater->id], 'sku' => 'SOFA-ROMA-BGE-3S', 'price' => 18500000, 'min_price' => 17000000, 'online_price' => 17900000, 'reorder_level' => 1000, 'reorder_qty' => 2000, 'is_active' => true],
            ],
        );

        $products->save(
            new Product,
            [
                'category_id' => $recliners->id,
                'name' => 'Oslo Power Recliner',
                'slug' => 'oslo-power-recliner',
                'brand' => 'Damro',
                'description' => '<p>Motorised footrest and headrest with USB charging port.</p>',
                'type' => ProductType::Stock,
                'width_mm' => 980,
                'depth_mm' => 960,
                'height_mm' => 1020,
                'weight_g' => 42000,
                'assembly_required' => true,
                'requires_delivery' => true,
                'is_serialized' => true,
                'publish_online' => true,
                'is_active' => true,
                'tags' => ['recliner', 'living room'],
            ],
            [],
            [
                ['value_ids' => [], 'sku' => 'REC-OSLO-01', 'price' => 12900000, 'min_price' => 11800000, 'online_price' => null, 'reorder_level' => 2000, 'reorder_qty' => 4000, 'is_active' => true],
            ],
        );

        $products->save(
            new Product,
            [
                'category_id' => $beds->id,
                'name' => 'Kandyan Solid Teak Bed Frame',
                'slug' => 'kandyan-solid-teak-bed-frame',
                'brand' => 'Teak Craft',
                'description' => '<p>Hand-finished seasoned Burma teak headboard and heavy slatted base.</p>',
                'type' => ProductType::MadeToOrder,
                'width_mm' => 1900,
                'depth_mm' => 2100,
                'height_mm' => 1150,
                'weight_g' => 78000,
                'assembly_required' => true,
                'requires_delivery' => true,
                'publish_online' => true,
                'is_active' => true,
                'tags' => ['bedroom', 'teak', 'solid wood'],
            ],
            [$size->id, $finish->id],
            [
                ['value_ids' => [$queen->id, $naturalTeak->id], 'sku' => 'BED-KDY-QN-NTK', 'price' => 22000000, 'min_price' => 20500000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true, 'suppliers' => [['supplier_id' => $teakHouse->id, 'supplier_sku' => 'TC-KDY-QN', 'last_cost' => 15500000, 'is_preferred' => true]]],
                ['value_ids' => [$king->id, $naturalTeak->id], 'sku' => 'BED-KDY-KG-NTK', 'price' => 25500000, 'min_price' => 23800000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true],
                ['value_ids' => [$queen->id, $walnut->id], 'sku' => 'BED-KDY-QN-WAL', 'price' => 22500000, 'min_price' => 21000000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true],
                ['value_ids' => [$king->id, $walnut->id], 'sku' => 'BED-KDY-KG-WAL', 'price' => 26000000, 'min_price' => 24200000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true],
            ],
        );

        $products->save(
            new Product,
            [
                'category_id' => $dining->id,
                'name' => 'Galle 6-Seater Dining Table',
                'slug' => 'galle-6-seater-dining-table',
                'brand' => 'Teak Craft',
                'description' => '<p>Solid teak dining table with bevelled edges.</p>',
                'type' => ProductType::Stock,
                'width_mm' => 1800,
                'depth_mm' => 950,
                'height_mm' => 760,
                'weight_g' => 48000,
                'assembly_required' => true,
                'requires_delivery' => true,
                'publish_online' => true,
                'is_active' => true,
                'tags' => ['dining', 'teak'],
            ],
            [],
            [
                ['value_ids' => [], 'sku' => 'DIN-GALLE-6S', 'price' => 16500000, 'min_price' => 15000000, 'online_price' => null, 'reorder_level' => 1000, 'reorder_qty' => 2000, 'is_active' => true],
            ],
        );

        $products->save(
            new Product,
            [
                'category_id' => $services->id,
                'name' => 'Colombo & Suburbs Lorry Delivery',
                'slug' => 'colombo-lorry-delivery',
                'type' => ProductType::Service,
                'warranty_months' => 0,
                'is_active' => true,
            ],
            [],
            [
                ['value_ids' => [], 'sku' => 'SRV-DEL-CMB', 'price' => 450000, 'min_price' => 300000, 'online_price' => 450000, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true],
            ],
        );

        $rawMaterials = Category::create(['name' => 'Raw Materials', 'slug' => 'raw-materials', 'sort_order' => 4, 'is_material' => true]);

        $products->save(
            new Product,
            [
                'category_id' => $rawMaterials->id,
                'name' => 'Seasoned Burma Teak Timber',
                'slug' => 'seasoned-burma-teak-timber',
                'type' => ProductType::Stock,
                'unit' => 'sqft',
                'publish_online' => false,
                'is_active' => true,
            ],
            [],
            [
                ['value_ids' => [], 'sku' => 'MAT-TEAK-SQFT', 'price' => 280000, 'min_price' => 240000, 'online_price' => null, 'reorder_level' => 50000, 'reorder_qty' => 150000, 'is_active' => true],
            ],
        );

        $products->save(
            new Product,
            [
                'category_id' => $rawMaterials->id,
                'name' => 'Emerald Velvet Upholstery Fabric',
                'slug' => 'emerald-velvet-upholstery-fabric',
                'type' => ProductType::Stock,
                'unit' => 'm',
                'publish_online' => false,
                'is_active' => true,
            ],
            [],
            [
                ['value_ids' => [], 'sku' => 'MAT-VEL-EMR', 'price' => 480000, 'min_price' => 420000, 'online_price' => null, 'reorder_level' => 15000, 'reorder_qty' => 40000, 'is_active' => true],
            ],
        );

        $products->save(
            new Product,
            [
                'category_id' => $rawMaterials->id,
                'name' => 'High-Density Cushion Foam (4 inch)',
                'slug' => 'high-density-cushion-foam-4in',
                'type' => ProductType::Stock,
                'unit' => 'sheet',
                'publish_online' => false,
                'is_active' => true,
            ],
            [],
            [
                ['value_ids' => [], 'sku' => 'MAT-FOAM-HD', 'price' => 820000, 'min_price' => 700000, 'online_price' => null, 'reorder_level' => 10000, 'reorder_qty' => 25000, 'is_active' => true],
            ],
        );
    }
}
