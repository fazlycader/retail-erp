<?php

use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\OpeningStockImportService;
use App\Services\Inventory\StockService;
use App\Support\Quantity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->showroom = Location::query()->create(['name' => 'Kandy Showroom', 'code' => 'KDY', 'type' => 'showroom', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create(['email' => 'owner@example.lk']);
    $this->dir = sys_get_temp_dir().'/golive-'.uniqid();
    mkdir($this->dir);

    $category = Category::query()->create(['name' => 'Sofas', 'slug' => 'sofas']);
    $this->sofa = app(ProductService::class)->save(new Product, ['category_id' => $category->id, 'name' => 'Roma Sofa', 'slug' => 'roma-sofa', 'type' => ProductType::Stock, 'is_active' => true], [],
        [['value_ids' => [], 'sku' => 'SOF-ROMA', 'price' => 12000000, 'min_price' => 10000000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]])->variants()->first();
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

function csvFile($t, string $name, array $rows): string
{
    $path = "{$t->dir}/{$name}.csv";
    $out = fopen($path, 'w');
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);

    return $path;
}

function reportRows(string $output): array
{
    preg_match('/Report \(every row that isn\'t plain\): (\S+)/', $output, $m);
    $rows = array_map('str_getcsv', file($m[1], FILE_IGNORE_NEW_LINES));

    return array_slice($rows, 1);
}

test('customers: dry run writes nothing, duplicates by phone are skipped and reported, re-runs add nothing', function () {
    Customer::query()->create(['name' => 'Kamala Perera', 'phone' => '+94771234567']);
    $file = csvFile($this, 'customers', [
        ['Name', 'Phone', 'Email', 'Type', 'Address', 'City'],
        ['Kamala P.', '077 123 4567', '', 'individual', '', ''],          // already a customer (other spelling)
        ['Nimal Silva', '0712345678', 'nimal@example.lk', '', '12 Lake Rd', 'Kandy'],
        ['N. Silva', '+94 71 234 5678', '', '', '', ''],                    // same phone as the line above
        ['Bad Phone', '12345', '', '', '', ''],
        ['Teak Villa (Pvt) Ltd', '0812233445', '', 'company', '', ''],      // unknown type
    ]);

    $this->artisan('golive:import', ['kind' => 'customers', 'file' => $file, '--user' => 'owner@example.lk'])->assertFailed();
    expect(Customer::query()->count())->toBe(1);

    Artisan::call('golive:import', ['kind' => 'customers', 'file' => $file, '--user' => 'owner@example.lk', '--commit' => true]);
    $report = reportRows(Artisan::output());

    expect(Customer::query()->count())->toBe(2)
        ->and(Customer::query()->where('phone', '+94712345678')->sole()->addresses[0]['city'])->toBe('Kandy')
        ->and(collect($report)->map(fn ($r) => [$r[0], $r[1]])->all())->toBe([['2', 'skip'], ['4', 'skip'], ['5', 'fail'], ['6', 'fail']])
        ->and($report[0][2])->toContain('Already a customer with this phone: "Kamala Perera" (file says "Kamala P.")')
        ->and($report[1][2])->toContain('Same phone as line 3');

    // Again: nothing new.
    Artisan::call('golive:import', ['kind' => 'customers', 'file' => $file, '--user' => 'owner@example.lk', '--commit' => true]);
    expect(Customer::query()->count())->toBe(2)->and(Artisan::output())->toContain('0 created');
});

test('suppliers and supplier balances', function () {
    Supplier::query()->create(['name' => 'Damro Manufacturing']);
    $suppliers = csvFile($this, 'suppliers', [
        ['name', 'phone', 'payment_terms_days', 'credit_limit'],
        ['damro  manufacturing', '0112855000', '45', ''],
        ['Moratuwa Teak Craft', '0112345678', '30', '500,000.00'],
        ['Kelani Fabrics', '', 'thirty', ''],
    ]);
    Artisan::call('golive:import', ['kind' => 'suppliers', 'file' => $suppliers, '--user' => 'owner@example.lk', '--commit' => true]);
    expect(Supplier::query()->count())->toBe(2)->and(Supplier::query()->where('name', 'Moratuwa Teak Craft')->value('credit_limit'))->toBe(50000000);

    $balances = csvFile($this, 'balances', [
        ['supplier', 'amount', 'as_of', 'reference'],
        ['Moratuwa Teak Craft', '125,000.50', '31/10/2026', ''],
        ['Nobody Ltd', '100', '', ''],
    ]);
    Artisan::call('golive:import', ['kind' => 'supplier-balances', 'file' => $balances, '--user' => 'owner@example.lk', '--commit' => true]);
    Artisan::call('golive:import', ['kind' => 'supplier-balances', 'file' => $balances, '--user' => 'owner@example.lk', '--commit' => true]);

    $bill = SupplierBill::query()->sole();
    expect($bill->total_amount)->toBe(12500050)
        ->and($bill->supplier_invoice_number)->toBe('OPENING-GO-LIVE')
        ->and($bill->bill_date->toDateString())->toBe('2026-10-31');
});

test('open orders: confirmed, stock reserved, dated as before, advance as a flagged imported payment without a shift', function () {
    Customer::query()->create(['name' => 'Kamala Perera', 'phone' => '+94771234567']);
    app(StockService::class)->move(['product_variant_id' => $this->sofa->id, 'location_id' => $this->showroom->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(2), 'unit_cost' => 7000000, 'user_id' => $this->admin->id]);

    $file = csvFile($this, 'orders', [
        ['order_ref', 'location', 'customer_phone', 'sku', 'description', 'quantity', 'unit_price', 'source', 'order_date', 'fulfilment', 'advance_paid', 'advance_date'],
        ['SO-1001', 'KDY', '0771234567', 'SOF-ROMA', '', '1', '120000', '', '2026-09-15', 'deliver', '50000', '2026-09-15'],
        ['SO-1001', 'KDY', '0771234567', '', 'Teak bench 2.4 m', '1', '65000', 'make', '', '', '', ''],
        ['SO-1002', 'KDY', '0771234567', 'SOF-ROMA', '', '5', '120000', '', '2026-09-20', '', '', ''],  // not enough stock
        ['SO-1003', 'KDY', '0779999999', 'SOF-ROMA', '', '1', '120000', '', '', '', '', ''],         // unknown customer
        ['SO-1004', 'GLL', '0771234567', 'NOPE', '', '1', '1000', '', '', '', '', ''],              // bad location and SKU
    ]);

    Artisan::call('golive:import', ['kind' => 'open-orders', 'file' => $file, '--user' => 'owner@example.lk']);
    expect(SalesOrder::query()->count())->toBe(0)->and(Artisan::output())->toContain('DRY RUN');

    Artisan::call('golive:import', ['kind' => 'open-orders', 'file' => $file, '--user' => 'owner@example.lk', '--commit' => true]);
    $report = collect(reportRows(Artisan::output()))->keyBy(0);

    $order = SalesOrder::query()->with('lines')->sole();
    $advance = Payment::query()->sole();

    expect($order->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($order->invoice_number)->not->toBeNull()
        ->and($order->business_date->toDateString())->toBe('2026-09-15')
        ->and($order->grand_total)->toBe(18500000)
        ->and($order->paid_amount)->toBe(5000000)
        ->and($order->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($order->lines)->toHaveCount(2)
        ->and($advance->imported)->toBeTrue()
        ->and($advance->shift_id)->toBeNull()
        ->and($advance->method->code)->toBe(PaymentMethodCode::Other)
        ->and($advance->business_date->toDateString())->toBe('2026-09-15')
        ->and(StockLevel::query()->where('product_variant_id', $this->sofa->id)->value('reserved'))->toBe(Quantity::fromUnits(1))
        ->and($report->keys()->map(fn ($k) => (string) $k)->all())->toBe(['2,3', '4', '5', '6']) // file lines (1 = header)
        ->and($report['2,3'][2])->toContain('imported payment')
        ->and($report[4][2])->toContain('Not imported')
        ->and($report[5][2])->toContain('import customers first')
        ->and($report[6][2])->toContain('not an active location code')->toContain('unknown SKU');

    // Again: the imported order is not created twice.
    Artisan::call('golive:import', ['kind' => 'open-orders', 'file' => $file, '--user' => 'owner@example.lk', '--commit' => true]);
    expect(SalesOrder::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1);
});

test('opening stock: an item that already has opening stock at the location is refused, not doubled', function () {
    $service = app(OpeningStockImportService::class);
    $file = fn () => UploadedFile::fake()->createWithContent('count.csv', "sku,quantity,unit_cost\nSOF-ROMA,3,70000\n");

    $first = $service->uploadAndDryRun($file(), $this->showroom, $this->admin);
    $service->commit($first, $this->admin);
    $second = $service->uploadAndDryRun($file(), $this->showroom, $this->admin);

    expect($second->counts['fail'])->toBe(1)
        ->and($second->rows->first()->reasons[0])->toContain('already has opening stock at KDY')
        ->and(iterator_to_array($service->reportRows($second))[1][0])->toBe('2')
        ->and(StockLevel::query()->where('product_variant_id', $this->sofa->id)->value('on_hand'))->toBe(Quantity::fromUnits(3));
});

test('templates and bad usage', function () {
    $this->artisan('golive:import', ['--template' => 'open-orders'])->expectsOutputToContain('required: order_ref, location, customer_phone, quantity, unit_price')->assertSuccessful();
    $this->artisan('golive:import', ['kind' => 'customers', 'file' => 'x.csv', '--user' => 'nobody@example.lk'])->assertFailed();
    $this->artisan('golive:import', ['kind' => 'customers', 'file' => '/no/such/file.csv', '--user' => 'owner@example.lk'])->expectsOutputToContain('File not found')->assertFailed();
});
