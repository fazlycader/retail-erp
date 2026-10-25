<?php

use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\User;
use App\Services\Cash\ShiftService;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Sales\PosCheckoutService;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * N+1 guard for the heaviest screens: the number of queries must not grow with the number of
 * products, locations' stock rows, sales or customers shown.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Asia/Colombo'));
    $this->kandy = Location::query()->create(['name' => 'Kandy Showroom', 'code' => 'KDY', 'type' => 'showroom', 'is_active' => true]);
    $this->galle = Location::query()->create(['name' => 'Galle Showroom', 'code' => 'GLL', 'type' => 'showroom', 'is_active' => true]);
    $this->admin = User::factory()->admin()->create();
    $this->admin->assignLocations($this->kandy->id);
    $this->cashier = User::factory()->create(['role' => Role::Cashier]);
    $this->cashier->assignLocations($this->kandy->id);
    $this->category = Category::query()->create(['name' => 'Chairs', 'slug' => 'chairs']);
    app(ShiftService::class)->open(Register::query()->where('location_id', $this->kandy->id)->sole(), $this->cashier, null, 0);
    $this->made = 0;
});

function queriesFor(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** $count more products with stock at both showrooms, each sold once at Kandy to a new customer. */
function addCatalogAndSales($t, int $count): void
{
    foreach (range(1, $count) as $i) {
        $n = ++$t->made;
        $variant = app(ProductService::class)->save(new Product, ['category_id' => $t->category->id, 'name' => "Test Chair {$n}", 'slug' => "test-chair-{$n}", 'type' => ProductType::Stock, 'is_active' => true], [],
            [['value_ids' => [], 'sku' => sprintf('TEST-%03d', $n), 'price' => 2500000, 'min_price' => 2000000, 'online_price' => null, 'reorder_level' => Quantity::fromUnits(20), 'reorder_qty' => 0, 'is_active' => true]])->variants()->first();

        foreach ([$t->kandy, $t->galle] as $location) {
            app(StockService::class)->move(['product_variant_id' => $variant->id, 'location_id' => $location->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(10), 'unit_cost' => 1200000, 'user_id' => $t->admin->id]);
        }

        $customer = Customer::query()->create(['name' => "Customer {$n}", 'phone' => sprintf('+9477%07d', $n)]);
        app(PosCheckoutService::class)->checkout([
            'customer_id' => $customer->id, 'location_id' => $t->kandy->id, 'kind' => 'cash_and_carry',
            'lines' => [['product_variant_id' => $variant->id, 'source' => 'stock', 'fulfil_location_id' => $t->kandy->id, 'quantity' => Quantity::fromUnits(1), 'unit_price' => 2500000]],
        ], [['payment_method_id' => PaymentMethod::byCode(PaymentMethodCode::Cash)->id, 'tendered' => 2500000]], $t->cashier);
    }
}

test('heavy screens do not run more queries when there is more data', function (string $name, Closure $request) {
    addCatalogAndSales($this, 2);
    $few = queriesFor(fn () => $request($this));

    addCatalogAndSales($this, 10);
    $many = queriesFor(fn () => $request($this));

    expect($many)->toBeLessThanOrEqual($few + 2, "{$name}: {$few} queries with little data, {$many} with more");
})->with([
    'stock matrix' => ['stock matrix', fn ($t) => $t->actingAs($t->admin)->get(route('stock.index'))->assertOk()],
    'POS search' => ['POS search', fn ($t) => $t->actingAs($t->cashier)->getJson(route('pos.products', ['q' => 'Test Chair']))->assertOk()->assertJsonCount($t->made <= 12 ? $t->made : 12, 'variants')],
    'POS category browse' => ['POS browse', fn ($t) => $t->actingAs($t->cashier)->getJson(route('pos.products', ['category_id' => $t->category->id]))->assertOk()],
    'POS screen' => ['POS screen', fn ($t) => $t->actingAs($t->cashier)->get(route('pos.index'))->assertOk()],
    'dashboard' => ['dashboard', fn ($t) => $t->actingAs($t->admin)->get(route('dashboard'))->assertOk()],
    'sales list' => ['sales list', fn ($t) => $t->actingAs($t->admin)->get(route('sales.index'))->assertOk()],
    'customers' => ['customers', fn ($t) => $t->actingAs($t->admin)->get(route('customers.index'))->assertOk()],
    'sales report' => ['sales report', fn ($t) => $t->actingAs($t->admin)->get(route('reports.show', ['report' => 'products', 'preset' => 'today']))->assertOk()],
    'stock valuation' => ['stock valuation', fn ($t) => $t->actingAs($t->admin)->get(route('reports.show', ['report' => 'stock_valuation', 'location' => 'all']))->assertOk()],
]);
