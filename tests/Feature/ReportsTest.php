<?php

use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\ProductType;
use App\Enums\ReturnOutcome;
use App\Enums\Role;
use App\Jobs\ExportReport;
use App\Mail\DailyReportMail;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DailyLocationSummary;
use App\Models\DailyVariantSummary;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Cash\CashCalculator;
use App\Services\Cash\DayCloseService;
use App\Services\Cash\ShiftService;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Reports\DailyOwnerReport;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRange;
use App\Services\Reports\SalesData;
use App\Services\Reports\SalesReader;
use App\Services\Sales\PosCheckoutService;
use App\Services\Sales\ReturnService;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Colombo')); // a Monday

    $this->kandy = Location::query()->create(['name' => 'Kandy Showroom', 'code' => 'KDY', 'type' => 'showroom', 'is_active' => true]);
    $this->galle = Location::query()->create(['name' => 'Galle Showroom', 'code' => 'GLL', 'type' => 'showroom', 'is_active' => true]);

    $make = function (string $name, Role $role, array $locations) {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'pin' => '2468', 'is_active' => true]);
        $user->assignLocations($locations[0]->id, array_map(fn ($l) => $l->id, array_slice($locations, 1)));

        return $user;
    };
    $this->admin = User::factory()->admin()->create(['name' => 'Owner']);
    $this->kandyCashier = $make('Nimal', Role::Cashier, [$this->kandy]);
    $this->galleCashier = $make('Sunil', Role::Cashier, [$this->galle]);
    $this->kandyManager = $make('Mala', Role::Manager, [$this->kandy]);
    $this->accountant = $make('Ruwan', Role::Accountant, [$this->kandy, $this->galle]);

    $category = Category::query()->create(['name' => 'Chairs', 'slug' => 'chairs']);
    $chair = app(ProductService::class)->save(new Product, ['category_id' => $category->id, 'name' => 'Oslo Chair', 'slug' => 'oslo-chair', 'type' => ProductType::Stock, 'is_active' => true], [],
        [['value_ids' => [], 'sku' => 'CHR-OSLO', 'price' => 2500000, 'min_price' => 2000000, 'online_price' => null, 'reorder_level' => 30000, 'reorder_qty' => 10000, 'is_active' => true]]);
    $sofaCat = Category::query()->create(['name' => 'Sofas', 'slug' => 'sofas']);
    $sofa = app(ProductService::class)->save(new Product, ['category_id' => $sofaCat->id, 'name' => 'Roma Sofa', 'slug' => 'roma-sofa', 'type' => ProductType::Stock, 'is_active' => true], [],
        [['value_ids' => [], 'sku' => 'SOF-ROMA', 'price' => 12000000, 'min_price' => 10000000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]]);
    $this->chair = $chair->variants()->first();
    $this->sofa = $sofa->variants()->first();

    foreach ([$this->kandy, $this->galle] as $location) {
        app(StockService::class)->move(['product_variant_id' => $this->chair->id, 'location_id' => $location->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(40), 'unit_cost' => 1200000, 'user_id' => $this->admin->id]);
        app(StockService::class)->move(['product_variant_id' => $this->sofa->id, 'location_id' => $location->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(5), 'unit_cost' => 7000000, 'user_id' => $this->admin->id]);
    }

    $this->customer = Customer::query()->create(['name' => 'Kamala', 'phone' => '+94771234500']);
    $this->method = fn (PaymentMethodCode $code) => PaymentMethod::byCode($code)->id;
    $this->shifts = app(ShiftService::class);
});

/** One trading day: sales at both showrooms, a split payment, a card sale, a return with a cash refund. */
function tradingDay($t): void
{
    $kandyShift = $t->shifts->open(Register::query()->where('location_id', $t->kandy->id)->sole(), $t->kandyCashier, null, 1000000);
    $galleShift = $t->shifts->open(Register::query()->where('location_id', $t->galle->id)->sole(), $t->galleCashier, null, 1000000);
    $sell = fn (Location $at, User $by, array $lines, array $payments, ?int $customer = null) => app(PosCheckoutService::class)->checkout([
        'customer_id' => $customer, 'location_id' => $at->id, 'kind' => 'cash_and_carry',
        'lines' => array_map(fn ($l) => ['product_variant_id' => $l[0]->id, 'source' => 'stock', 'fulfil_location_id' => $at->id, 'quantity' => Quantity::fromUnits($l[1]), 'unit_price' => $l[0]->price, 'discount_type' => $l[2] ?? null, 'discount_value' => $l[3] ?? 0], $lines),
    ], $payments, $by);

    $a = $sell($t->kandy, $t->kandyCashier, [[$t->chair, 2, 'percent', 500], [$t->sofa, 1]], [
        ['payment_method_id' => ($t->method)(PaymentMethodCode::Card), 'amount' => 10000000, 'reference' => 'C1'],
        ['payment_method_id' => ($t->method)(PaymentMethodCode::Cash), 'tendered' => 7000000],
    ], $t->customer->id);
    $sell($t->galle, $t->galleCashier, [[$t->chair, 4]], [['payment_method_id' => ($t->method)(PaymentMethodCode::Cash), 'tendered' => 10000000]]);

    app(ReturnService::class)->process($a, [['sales_order_line_id' => $a->lines->first()->id, 'quantity' => Quantity::fromUnits(1), 'condition' => 'resaleable']], ReturnOutcome::Refund, 'Wobbly', $t->kandyCashier, ($t->method)(PaymentMethodCode::Cash));

    foreach ([[$kandyShift, $t->kandyCashier], [$galleShift, $t->galleCashier]] as [$shift, $cashier]) {
        $t->shifts->close($shift, $cashier, null, CashCalculator::expected($shift->fresh())['expected'], null, null);
    }
}

test('summary rows equal the live queries', function () {
    tradingDay($this);                              // Monday
    $this->travel(1)->days();
    tradingDay($this);                              // Tuesday
    $this->travel(1)->days();                       // today: Wednesday

    $range = new ReportRange([$this->kandy->id, $this->galle->id], '2026-10-19', '2026-10-21');
    $live = app(SalesData::class);
    $reader = app(SalesReader::class);

    $before = [$reader->totals($range), $reader->byMethod($range), $reader->byChannel($range), $reader->bySalesperson($range), $reader->variants($range)->sortBy(['location_id', 'name'])->values()->all()];
    expect(DailyLocationSummary::query()->count())->toBe(0);

    Artisan::call('reports:summarize', ['--days' => 3]);

    expect(DailyLocationSummary::query()->count())->toBe(6) // 2 locations × 3 past days (Sat, Mon, Tue… the last 3 business days)
        ->and(DailyVariantSummary::query()->whereDate('business_date', '2026-10-19')->count())->toBe(3);

    // The same figures from the summary tables (past days) + live (today).
    $after = [$reader->totals($range), $reader->byMethod($range), $reader->byChannel($range), $reader->bySalesperson($range), $reader->variants($range)->sortBy(['location_id', 'name'])->values()->all()];
    expect($after)->toBe($before);

    // And equal to a fully live calculation.
    expect($reader->totals($range))->toBe($live->totals($range));
    $t = $live->totals(new ReportRange([$this->kandy->id], '2026-10-19', '2026-10-19'));
    expect($t['invoices_count'])->toBe(1)
        ->and($t['sales'])->toBe(4750000 + 12000000)
        ->and($t['returns_total'])->toBe(2375000)
        ->and($t['refunds'])->toBe(2375000)
        ->and($t['cost'])->toBe(2 * 1200000 + 7000000)
        ->and($t['returns_cost'])->toBe(1200000);
});

test('every report builds, prints and exports', function () {
    tradingDay($this);

    foreach (array_keys(ReportCatalog::all()) as $key) {
        $this->actingAs($this->admin)->get(route('reports.show', ['report' => $key, 'preset' => 'today', 'location' => 'all']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Show')->where('error', null)->has('data.tables'));
        $this->actingAs($this->admin)->get(route('reports.print', ['report' => $key, 'preset' => 'today', 'location' => 'all']))->assertOk();
    }

    $this->actingAs($this->admin)->get(route('reports.show', ['report' => 'sales', 'preset' => 'today', 'location' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->where('data.tables.1.key', 'locations')->has('data.tables.1.rows', 2)->where('data.cards.0.value', 16750000 + 10000000));

    $csv = $this->actingAs($this->admin)->get(route('reports.export', ['report' => 'products', 'preset' => 'today', 'location' => 'all', 'format' => 'csv']))->assertOk();
    expect($csv->headers->get('content-disposition'))->toContain('products-products-2026-10-19.csv');
    $this->actingAs($this->admin)->get(route('reports.export', ['report' => 'stock_valuation', 'location' => 'all', 'format' => 'xlsx']))->assertOk();

    // Background exports run as a job and are downloaded by the same user only.
    $export = new ReportExport;
    $export->forceFill(['user_id' => $this->admin->id, 'report' => 'sales', 'format' => 'csv', 'filters' => ['from' => '2026-10-19', 'to' => '2026-10-19', 'table' => 'days', 'locations' => [$this->kandy->id]], 'status' => 'queued'])->save();
    (new ExportReport($export->id))->handle(app(ReportBuilder::class), app(ReportExporter::class));
    expect($export->fresh()->status)->toBe('ready');
    $this->actingAs($this->admin)->get(route('reports.exports.download', $export))->assertOk();
    $this->actingAs($this->kandyManager)->get(route('reports.exports.download', $export))->assertForbidden();
});

test('report permissions and location scope', function () {
    tradingDay($this);

    $this->actingAs($this->kandyCashier)->get(route('reports.index'))->assertForbidden();
    $this->actingAs($this->kandyCashier)->get(route('reports.show', 'sales'))->assertForbidden();
    $storekeeper = User::factory()->create(['role' => Role::Storekeeper]);
    $storekeeper->assignLocations($this->kandy->id);
    $this->actingAs($storekeeper)->get(route('reports.show', 'stock_valuation'))->assertForbidden();

    // A Kandy manager asking for Galle (or "all") only ever gets Kandy.
    $this->actingAs($this->kandyManager)->get(route('reports.show', ['report' => 'sales', 'location' => $this->galle->id]))
        ->assertInertia(fn (Assert $page) => $page->has('locations', 1)->where('location', (string) $this->kandy->id)->has('data.tables.1.rows', 1)->where('data.tables.1.rows.0.location', 'Kandy Showroom'));
    $this->actingAs($this->kandyManager)->get(route('reports.show', ['report' => 'sales', 'location' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('data.tables.1.rows', 1));
});

test('profit is hidden from managers unless managers_see_profit is on', function () {
    tradingDay($this);
    $sales = route('reports.show', ['report' => 'sales', 'preset' => 'today']);

    $this->actingAs($this->kandyManager)->get(route('reports.show', 'profit'))->assertForbidden();
    $this->actingAs($this->kandyManager)->get($sales)->assertInertia(fn (Assert $page) => $page
        ->where('data.cards', fn ($cards) => collect($cards)->pluck('key')->doesntContain('gross_profit'))
        ->where('data.tables.0.columns', fn ($cols) => collect($cols)->pluck('key')->doesntContain('gross_profit'))
        ->missing('data.tables.0.rows.0.gross_profit'));
    $this->actingAs($this->kandyManager)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('owner.canSeeProfit', false)->missing('owner.totals.gross_profit')->missing('owner.locations.0.gross_profit'));
    $this->actingAs($this->kandyManager)->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.reports', fn ($r) => collect($r)->pluck('key')->doesntContain('profit')));

    // Admins and accountants always see it.
    $this->actingAs($this->accountant)->get(route('reports.show', ['report' => 'profit', 'preset' => 'today', 'location' => 'all']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('data.cards.2.key', 'gross_profit'));
    $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('owner.totals.gross_profit'));

    // Setting on: managers too.
    CompanySetting::current()->update(['managers_see_profit' => true]);
    $this->actingAs($this->kandyManager)->get(route('reports.show', 'profit'))->assertOk();
    $this->actingAs($this->kandyManager)->get($sales)->assertInertia(fn (Assert $page) => $page->has('data.tables.0.rows.0.gross_profit'));
});

test('the owner dashboard: admins see every location, managers theirs', function () {
    tradingDay($this);

    $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('owner.locations', 2)
        ->where('owner.totals.invoices', 2)
        ->where('owner.totals.net_sales', 16750000 - 2375000 + 10000000)
        ->where('owner.totals.cash', (16750000 - 10000000) + 10000000 - 2375000)
        ->where('owner.lowStock', 0));

    $this->actingAs($this->kandyManager)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('owner.locations', 1)->where('owner.locations.0.code', 'KDY'));
    $this->actingAs($this->kandyCashier)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('owner', null));
});

test('the daily email goes once: after the last location closes, or by the safety net', function () {
    Mail::fake();
    CompanySetting::current()->update(['report_emails' => ['owner@example.com']]);
    tradingDay($this);
    $days = app(DayCloseService::class);

    $days->close($this->kandy->id, '2026-10-19', $this->kandyManager, true);
    Mail::assertNothingSent();

    $days->close($this->galle->id, '2026-10-19', $this->admin, true);
    Mail::assertSent(DailyReportMail::class, fn ($mail) => $mail->report['unclosed'] === [] && count($mail->report['locations']) === 2 && $mail->hasTo('owner@example.com'));
    expect(DailyLocationSummary::query()->whereDate('business_date', '2026-10-19')->count())->toBe(2);

    // The safety net doesn't send it again.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Asia/Colombo'));
    Artisan::call('reports:daily-email');
    Mail::assertSentCount(1);

    // Next day nobody closes: the safety net sends at 07:00 the day after, listing them.
    tradingDay($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 06:55', 'Asia/Colombo'));
    Artisan::call('reports:daily-email');
    Mail::assertSentCount(1);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 07:05', 'Asia/Colombo'));
    Artisan::call('reports:daily-email');
    Artisan::call('reports:daily-email');
    Mail::assertSentCount(2);
    Mail::assertSent(DailyReportMail::class, fn ($mail) => $mail->report['business_date'] === '2026-10-20' && $mail->report['unclosed'] === ['Kandy Showroom', 'Galle Showroom']);

    (new DailyReportMail(app(DailyOwnerReport::class)->build('2026-10-20'), 'Teak House'))->render();
});

test('low stock and dead stock', function () {
    tradingDay($this);
    $this->actingAs($this->admin)->get(route('reports.show', ['report' => 'low_stock', 'location' => (string) $this->galle->id]))
        ->assertInertia(fn (Assert $page) => $page->where('data.cards.0.value', 0));

    // Galle sold 4 of 40 chairs; a reorder level of 36 makes them low there.
    $this->chair->forceFill(['reorder_level' => Quantity::fromUnits(36)])->saveQuietly();
    $this->actingAs($this->admin)->get(route('reports.show', ['report' => 'low_stock', 'location' => (string) $this->galle->id]))
        ->assertInertia(fn (Assert $page) => $page->where('data.cards.0.value', 1)->where('data.tables.0.rows.0.sku', 'CHR-OSLO'));

    // Sofas never sold at Galle: dead stock, 5 × 70,000 tied up.
    $this->actingAs($this->admin)->get(route('reports.show', ['report' => 'dead_stock', 'location' => (string) $this->galle->id, 'days' => 30]))
        ->assertInertia(fn (Assert $page) => $page->where('days', 30)->where('data.cards.0.value', 1)->where('data.cards.1.value', 35000000));
});
