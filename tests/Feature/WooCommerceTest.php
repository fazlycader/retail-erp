<?php

use App\Console\Commands\WooPluginPackage;
use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Facades\Integrations;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\IntegrationCredential;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\StorefrontEnquiry;
use App\Models\User;
use App\Models\WooLink;
use App\Models\WooSyncItem;
use App\Models\WooSyncLog;
use App\Models\WooWebhookEvent;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockService;
use App\Services\Sales\SalesOrderService;
use App\Services\Woo\OnlineStock;
use App\Services\Woo\OrderIntake;
use App\Services\Woo\WooLinker;
use App\Services\Woo\WooPusher;
use App\Services\Woo\WooReconciler;
use App\Support\Quantity;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const SHOP = 'https://shop.test/wp-json/wc/v3/';

beforeEach(function () {
    Sleep::fake();
    foreach (['WOO_STORE_URL' => 'https://shop.test', 'WOO_CONSUMER_KEY' => 'ck_test', 'WOO_CONSUMER_SECRET' => 'cs_test', 'WOO_WEBHOOK_SECRET' => 'hook-secret'] as $k => $v) {
        Integrations::set($k, $v, null);
    }

    $this->kandy = Location::query()->create(['name' => 'Kandy Showroom', 'code' => 'KDY', 'type' => 'showroom', 'is_active' => true]);
    $this->galle = Location::query()->create(['name' => 'Galle Showroom', 'code' => 'GLL', 'type' => 'showroom', 'is_active' => true]);
    $this->warehouse = Location::query()->create(['name' => 'Warehouse', 'code' => 'WH', 'type' => 'warehouse', 'is_active' => true]);
    CompanySetting::current()->update(['online_stock_locations' => [$this->kandy->id, $this->galle->id], 'web_fulfilment_location_id' => $this->kandy->id, 'prices_include_vat' => true]);
    $this->admin = User::factory()->admin()->create();

    $category = Category::query()->create(['name' => 'Chairs', 'slug' => 'chairs']);
    $this->chair = app(ProductService::class)->save(new Product, ['category_id' => $category->id, 'name' => 'Oslo Chair', 'slug' => 'oslo-chair', 'type' => ProductType::Stock, 'is_active' => true, 'publish_online' => true, 'width_mm' => 450, 'weight_g' => 7500], [],
        [['value_ids' => [], 'sku' => 'CHR-OSLO', 'price' => 2500000, 'min_price' => 2000000, 'online_price' => 2400000, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]]);

    $colour = Attribute::query()->create(['name' => 'Colour', 'slug' => 'colour']);
    $grey = AttributeValue::query()->create(['attribute_id' => $colour->id, 'value' => 'Grey', 'code' => 'GRY']);
    $blue = AttributeValue::query()->create(['attribute_id' => $colour->id, 'value' => 'Blue', 'code' => 'BLU']);
    $this->sofa = app(ProductService::class)->save(new Product, ['category_id' => $category->id, 'name' => 'Roma Sofa', 'slug' => 'roma-sofa', 'type' => ProductType::Stock, 'is_active' => true, 'publish_online' => true], [$colour->id],
        [['value_ids' => [$grey->id], 'sku' => 'SOF-GRY', 'price' => 12000000, 'min_price' => 0, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true],
            ['value_ids' => [$blue->id], 'sku' => 'SOF-BLU', 'price' => 12500000, 'min_price' => 0, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]]);

    $this->chairVariant = $this->chair->variants()->first();
    $this->move = fn (Product|int $variant, Location $at, int $units) => app(StockService::class)->move(['product_variant_id' => is_int($variant) ? $variant : $variant->variants()->first()->id, 'location_id' => $at->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits($units), 'unit_cost' => 1000000, 'user_id' => $this->admin->id]);
    WooSyncItem::query()->delete();
});

/** Http::fake() adds to earlier stubs; start each scenario from a clean client. */
function wooFake(array $stubs): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

/** @return Collection<int, HttpRequest> */
function wooCalls()
{
    return collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
}

function wooOrder(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 501, 'number' => '1501', 'status' => 'processing', 'currency' => 'LKR',
        'total' => '48000.00', 'shipping_total' => '0.00', 'shipping_tax' => '0.00',
        'payment_method' => 'payhere', 'payment_method_title' => 'PayHere (card)', 'transaction_id' => 'PH-778899', 'date_paid_gmt' => '2026-10-20T08:00:00',
        'billing' => ['first_name' => 'Kamala', 'last_name' => 'Perera', 'email' => 'kamala@example.lk', 'phone' => '077 123 4567', 'address_1' => '12 Lake Road', 'city' => 'Kandy', 'state' => 'Central'],
        'shipping' => ['address_1' => '12 Lake Road', 'city' => 'Kandy', 'state' => 'Central'],
        'shipping_lines' => [['method_id' => 'flat_rate']],
        'line_items' => [['name' => 'Oslo Chair', 'sku' => 'CHR-OSLO', 'quantity' => 2, 'total' => '48000.00', 'total_tax' => '0.00']],
        'customer_note' => 'Call before delivery',
    ], $overrides);
}

function signedWebhook($t, array $body, string $deliveryId = 'd-1', string $topic = 'order.created', ?string $secret = 'hook-secret')
{
    $raw = json_encode($body);
    $headers = ['X-WC-Webhook-Topic' => $topic, 'X-WC-Webhook-Delivery-ID' => $deliveryId, 'Content-Type' => 'application/json'];
    if ($secret !== null) {
        $headers['X-WC-Webhook-Signature'] = base64_encode(hash_hmac('sha256', $raw, $secret, true));
    }

    return $t->call('POST', '/api/woo/webhook', [], [], [], collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'], $raw);
}

// ---- Stock formula ---------------------------------------------------------------------------

test('online stock = available at online locations (each ≥ 0) − buffer, never below 0', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    ($this->move)($this->chair, $this->galle, 3);
    ($this->move)($this->chair, $this->warehouse, 40); // not an online location

    app(ReservationService::class)->reserve($this->chairVariant, $this->kandy->id, Quantity::fromUnits(2));
    expect(OnlineStock::forVariant($this->chairVariant->id))->toBe(6);

    $this->chairVariant->update(['online_buffer' => Quantity::fromUnits(4)]);
    expect(OnlineStock::forVariant($this->chairVariant->id))->toBe(2);

    $this->chairVariant->update(['online_buffer' => Quantity::fromUnits(10)]);
    expect(OnlineStock::forVariant($this->chairVariant->id))->toBe(0);

    // A location with negative stock doesn't cancel another's.
    CompanySetting::current()->update(['allow_negative_stock' => true]);
    $this->chairVariant->update(['online_buffer' => 0]);
    app(StockService::class)->move(['product_variant_id' => $this->chairVariant->id, 'location_id' => $this->galle->id, 'type' => MovementType::AdjustmentOut, 'quantity' => -Quantity::fromUnits(5), 'user_id' => $this->admin->id]);
    expect(OnlineStock::forVariant($this->chairVariant->id))->toBe(3);
});

test('stock and price changes mark the product dirty after commit only', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    expect(WooSyncItem::query()->pluck('item_key')->all())->toBe(["product:{$this->chair->id}"]);

    WooSyncItem::query()->delete();
    try {
        DB::transaction(function () {
            ($this->move)($this->chair, $this->kandy, 1);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    expect(WooSyncItem::query()->count())->toBe(0);

    $this->chairVariant->update(['price' => 2600000]);
    $this->chairVariant->update(['price' => 2700000]);
    expect(WooSyncItem::query()->count())->toBe(1); // coalesced
});

// ---- Outbound push ------------------------------------------------------------------------------

test('push: batch payload shapes for simple and variable products, then unchanged payloads are skipped', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    foreach ($this->sofa->variants as $v) {
        ($this->move)($v->id, $this->galle, 2);
    }
    WooPusher::markAll();

    wooFake([
        SHOP.'products?sku=*' => Http::response([]),
        SHOP.'products/categories?slug=chairs' => Http::response([]),
        SHOP.'products/categories' => Http::response(['id' => 7]),
        SHOP.'products/batch' => Http::response(['create' => [['id' => 101], ['id' => 102]]]),
        SHOP.'products/102/variations/batch' => Http::response(['create' => [['id' => 201], ['id' => 202]]]),
    ]);

    $stats = app(WooPusher::class)->run();
    expect($stats)->toMatchArray(['products' => 2, 'variations' => 2, 'failed' => 0]);

    $batch = wooCalls()->first(fn (HttpRequest $r) => str_ends_with($r->url(), 'products/batch'))->data();
    [$chair, $sofa] = $batch['create'];
    expect($chair)->toMatchArray(['type' => 'simple', 'sku' => 'CHR-OSLO', 'regular_price' => '24000.00', 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock', 'status' => 'publish', 'categories' => [['id' => 7]], 'weight' => '7.5'])
        ->and($chair['dimensions']['width'])->toBe('45')
        ->and($sofa['type'])->toBe('variable')
        ->and($sofa['attributes'][0])->toMatchArray(['name' => 'Colour', 'variation' => true, 'options' => ['Grey', 'Blue']])
        ->and($sofa)->not->toHaveKey('sku');

    $variations = wooCalls()->first(fn (HttpRequest $r) => str_contains($r->url(), 'variations/batch'))->data()['create'];
    expect($variations[0])->toMatchArray(['sku' => 'SOF-GRY', 'regular_price' => '120000.00', 'stock_quantity' => 2, 'attributes' => [['name' => 'Colour', 'option' => 'Grey']]])
        ->and(WooLink::for(WooLink::VARIANT, $this->chairVariant->id)->woo_id)->toBe(101);

    // Nothing changed: no calls at all.
    wooFake([]);
    WooPusher::markAll();
    expect(app(WooPusher::class)->run())->toMatchArray(['products' => 0, 'variations' => 0, 'failed' => 0])->and(WooSyncItem::query()->count())->toBe(0)->and(wooCalls())->toBeEmpty();

    // One sale-like stock change: only the chair goes again, as an update with the new quantity.
    wooFake([SHOP.'products/batch' => Http::response(['update' => [['id' => 101]]])]);
    app(StockService::class)->move(['product_variant_id' => $this->chairVariant->id, 'location_id' => $this->kandy->id, 'type' => MovementType::Sale, 'quantity' => -Quantity::fromUnits(1), 'user_id' => $this->admin->id]);
    app(WooPusher::class)->run();
    $update = wooCalls()->first(fn (HttpRequest $r) => str_ends_with($r->url(), 'products/batch'))->data()['update'][0];
    expect($update)->toMatchArray(['id' => 101, 'stock_quantity' => 4]);
});

test('push retries 5xx, backs off after repeated failure, and links a SKU already in WooCommerce instead of duplicating', function () {
    WooLink::remember(WooLink::CATEGORY, $this->chair->category_id, 7);
    ($this->move)($this->chair, $this->kandy, 2);

    wooFake([
        SHOP.'products?sku=CHR-OSLO' => Http::response([['id' => 900, 'parent_id' => 0]]),
        SHOP.'products/batch' => Http::sequence()->push(['message' => 'busy'], 503)->push(['update' => [['id' => 900]]]),
    ]);
    expect(app(WooPusher::class)->run(limit: 1))->toMatchArray(['products' => 1, 'failed' => 0]);
    expect(WooLink::for(WooLink::PRODUCT, $this->chair->id)->woo_id)->toBe(900);
    $sent = wooCalls()->filter(fn (HttpRequest $r) => str_ends_with($r->url(), 'products/batch'));
    expect($sent)->toHaveCount(2)->and($sent->last()->data())->toHaveKey('update')->not->toHaveKey('create');

    // Down for good: the item waits with backoff and the error is kept.
    wooFake([SHOP.'*' => Http::response(['message' => 'down'], 500)]);
    $this->chairVariant->update(['online_price' => 2300000]); // the price the shop shows
    expect(app(WooPusher::class)->run(limit: 1)['failed'])->toBe(1);
    $item = WooSyncItem::query()->sole();
    expect($item->attempts)->toBe(1)->and($item->available_at->isFuture())->toBeTrue()->and($item->last_error)->toContain('WooCommerce 500');
});

test('products taken offline are set to draft; never-linked offline products are dropped', function () {
    WooLink::remember(WooLink::PRODUCT, $this->chair->id, 101);
    $this->chair->update(['publish_online' => false]);
    $this->sofa->update(['publish_online' => false]);

    wooFake([SHOP.'products/batch' => Http::response(['update' => [['id' => 101]]])]);
    app(WooPusher::class)->run();

    $batch = wooCalls()->first(fn (HttpRequest $r) => str_ends_with($r->url(), 'products/batch'))->data();
    expect($batch)->toBe(['update' => [['id' => 101, 'status' => 'draft']]])->and(WooSyncItem::query()->count())->toBe(0);
});

test('woo:link matches by SKU, reports both sides, and never creates', function () {
    wooFake([
        SHOP.'products?status=any*' => Http::response([
            ['id' => 101, 'type' => 'simple', 'sku' => 'chr-oslo'],
            ['id' => 102, 'type' => 'variable', 'sku' => ''],
            ['id' => 103, 'type' => 'simple', 'sku' => 'OLD-TABLE'],
        ], 200, ['X-WP-TotalPages' => '1']),
        SHOP.'products/102/variations*' => Http::response([['id' => 201, 'sku' => 'SOF-GRY'], ['id' => 202, 'sku' => 'SOF-BLU']], 200, ['X-WP-TotalPages' => '1']),
    ]);

    $dry = app(WooLinker::class)->run(dryRun: true);
    expect($dry['linked'])->toBe(['CHR-OSLO', 'SOF-GRY', 'SOF-BLU'])->and($dry['woo_only'])->toBe(['OLD-TABLE'])->and(WooLink::query()->count())->toBe(0);

    app(WooLinker::class)->run(dryRun: false);
    expect(WooLink::for(WooLink::PRODUCT, $this->sofa->id)->woo_id)->toBe(102)
        ->and(WooLink::query()->where('local_type', WooLink::VARIANT)->where('woo_parent_id', 102)->count())->toBe(2);
    wooCalls()->each(fn (HttpRequest $r) => expect($r->method())->toBe('GET'));
});

// ---- Webhooks -------------------------------------------------------------------------------------

test('webhook signature: valid accepted, tampered and missing refused with 401 (logged without the body)', function () {
    ($this->move)($this->chair, $this->kandy, 5);

    signedWebhook($this, wooOrder())->assertOk()->assertJsonPath('status', 'queued');
    signedWebhook($this, wooOrder(['id' => 502]), 'd-2', secret: 'wrong')->assertStatus(401);
    signedWebhook($this, wooOrder(['id' => 503]), 'd-3', secret: null)->assertStatus(401);

    expect(WooWebhookEvent::query()->count())->toBe(1)
        ->and(SalesOrder::query()->count())->toBe(1)
        ->and(WooSyncLog::query()->where('action', 'webhook')->where('level', 'warning')->count())->toBe(2)
        ->and(WooSyncLog::query()->where('action', 'webhook')->first()->context)->not->toHaveKey('body');
});

test('a redelivered webhook is recorded once and creates one order', function () {
    ($this->move)($this->chair, $this->kandy, 5);

    signedWebhook($this, wooOrder(), 'same-id')->assertOk();
    signedWebhook($this, wooOrder(), 'same-id')->assertOk()->assertJsonPath('status', 'duplicate');
    // A new delivery of the same order (order.updated) doesn't duplicate it either.
    signedWebhook($this, wooOrder(), 'other-id', 'order.updated')->assertOk();

    expect(WooWebhookEvent::query()->count())->toBe(2)->and(SalesOrder::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1);
});

// ---- Order intake ------------------------------------------------------------------------------

test('a paid web order: web channel, customer created, delivery, stock reserved at the fulfilment location, payment Online', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    $order = app(OrderIntake::class)->process(wooOrder(['shipping_total' => '1500.00', 'total' => '49500.00']));

    $customer = Customer::query()->sole();
    $payment = Payment::query()->sole();
    expect($order->channel->value)->toBe('web')
        ->and($order->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($order->woo_order_number)->toBe('1501')
        ->and($order->needs_attention)->toBeFalse()
        ->and($order->fulfilment_type->value)->toBe('deliver')
        ->and($order->delivery_address['city'])->toBe('Kandy')
        ->and($order->grand_total)->toBe(4950000)
        ->and($customer->phone)->toBe('+94771234567')->and($customer->email)->toBe('kamala@example.lk')
        ->and($payment->method->code)->toBe(PaymentMethodCode::Online)
        ->and($payment->reference)->toBe('PH-778899')->and($payment->notes)->toContain('PayHere')->and($payment->shift_id)->toBeNull()
        ->and($order->balance_due)->toBe(0)
        ->and(StockLevel::query()->where('location_id', $this->kandy->id)->value('reserved'))->toBe(Quantity::fromUnits(2));

    // The customer is matched by email next time (no phone given).
    $second = app(OrderIntake::class)->process(wooOrder(['id' => 502, 'number' => '1502', 'billing' => ['phone' => '']]));
    expect($second->customer_id)->toBe($customer->id);
});

test('an unknown SKU puts the order on needs attention instead of guessing', function () {
    $order = app(OrderIntake::class)->process(wooOrder(['line_items' => [['name' => 'Mystery Lamp', 'sku' => 'LAMP-99', 'quantity' => 1, 'total' => '48000.00', 'total_tax' => '0.00']]]));

    expect($order->needs_attention)->toBeTrue()
        ->and($order->attention_reason)->toContain('Unknown SKU LAMP-99')
        ->and($order->status)->toBe(SalesOrderStatus::Draft) // not confirmed, nothing reserved
        ->and($order->lines->first()->is_custom)->toBeTrue();
});

test('cancelled in WooCommerce: unpaid orders are cancelled; paid ones release stock and are flagged, money untouched', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    $unpaid = app(OrderIntake::class)->process(wooOrder(['id' => 600, 'number' => '1600', 'status' => 'on-hold']));
    expect(Payment::query()->count())->toBe(0);
    app(OrderIntake::class)->process(wooOrder(['id' => 600, 'number' => '1600', 'status' => 'cancelled']));
    expect($unpaid->fresh()->status)->toBe(SalesOrderStatus::Cancelled);

    $paid = app(OrderIntake::class)->process(wooOrder(['id' => 601, 'number' => '1601']));
    app(OrderIntake::class)->process(wooOrder(['id' => 601, 'number' => '1601', 'status' => 'refunded', 'refunds' => [['total' => '-48000.00']]]));

    $paid->refresh();
    expect($paid->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($paid->needs_attention)->toBeTrue()
        ->and($paid->attention_reason)->toContain('Refunded in WooCommerce')
        ->and($paid->paid_amount)->toBe(4800000) // never moved automatically
        ->and(StockLevel::query()->where('location_id', $this->kandy->id)->value('reserved'))->toBe(0);
});

test('fulfilled in the app: the Woo order is set to completed with a note', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    $order = app(OrderIntake::class)->process(wooOrder());
    WooSyncItem::query()->delete();

    app(SalesOrderService::class)->fulfil($order, [$order->lines->first()->id => Quantity::fromUnits(2)], $this->admin);
    expect(WooSyncItem::query()->where('kind', WooSyncItem::ORDER_STATUS)->count())->toBe(1);

    wooFake([SHOP.'products/batch' => Http::response(['update' => []]), SHOP.'*' => Http::response(['id' => 1])]);
    expect(app(WooPusher::class)->run()['orders'])->toBe(1);

    $calls = wooCalls();
    expect($calls->first(fn ($r) => $r->url() === SHOP.'orders/501')->data())->toBe(['status' => 'completed'])
        ->and($calls->first(fn ($r) => $r->url() === SHOP.'orders/501/notes')->data()['note'])->toContain("delivery note {$order->number}")
        ->and($order->fresh()->woo_status)->toBe('completed');
});

test('reconcile processes orders the webhooks missed and re-pushes stock that differs', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    WooLink::remember(WooLink::PRODUCT, $this->chair->id, 101, null, 'old-hash');
    WooLink::remember(WooLink::VARIANT, $this->chairVariant->id, 101);
    WooSyncItem::query()->delete();
    CompanySetting::current()->forceFill(['woo_order_cursor' => '2026-10-20 00:00:00'])->save();

    wooFake([
        SHOP.'orders*' => Http::response([wooOrder(['date_modified_gmt' => '2026-10-21T09:00:00'])], 200, ['X-WP-TotalPages' => '1']),
        SHOP.'products?include=*' => Http::response([['id' => 101, 'type' => 'simple', 'manage_stock' => true, 'stock_quantity' => 9]]),
    ]);

    $stats = app(WooReconciler::class)->run();
    expect($stats)->toMatchArray(['orders' => 1, 'stock_mismatches' => 1])
        ->and(SalesOrder::query()->where('woo_order_id', 501)->exists())->toBeTrue()
        ->and(CompanySetting::current()->fresh()->woo_order_cursor->utc()->toIso8601String())->toBe('2026-10-21T09:00:00+00:00')
        ->and(WooSyncItem::query()->where('item_key', "product:{$this->chair->id}")->exists())->toBeTrue()
        ->and(WooLink::for(WooLink::PRODUCT, $this->chair->id)->last_pushed_hash)->toBeNull();

    // Running again changes nothing.
    app(WooReconciler::class)->run();
    expect(SalesOrder::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1);
});

// ---- Storefront API (WordPress plugin) -----------------------------------------------------------

test('storefront API: token ability, availability buckets per showroom, enquiries with honeypot and rate limit', function () {
    ($this->move)($this->chair, $this->kandy, 5);
    ($this->move)($this->chair, $this->galle, 1);
    $token = OrderIntake::actor()->createToken('storefront', ['storefront:read'])->plainTextToken;
    $other = OrderIntake::actor()->createToken('other', ['something-else'])->plainTextToken;

    $this->getJson('/api/storefront/availability?sku=CHR-OSLO')->assertUnauthorized();
    $this->withToken($other)->getJson('/api/storefront/availability?sku=CHR-OSLO')->assertForbidden();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/storefront/availability?sku=CHR-OSLO')->assertOk()
        ->assertJsonCount(2, 'locations') // showrooms only, not the warehouse
        ->assertJsonPath('locations.0.label', 'In stock')
        ->assertJsonPath('locations.1.label', 'Only 1 left')
        ->assertHeaderContains('Cache-Control', 'no-store');
    $this->withToken($token)->getJson('/api/storefront/availability?sku=NOPE')->assertNotFound();

    $form = ['name' => 'Nimal', 'phone' => '0712345678', 'sku' => 'CHR-OSLO', 'location' => 'kdy'];
    $this->withToken($token)->postJson('/api/storefront/enquiries', [...$form, 'website' => 'spam.example'])->assertUnprocessable();
    $this->withToken($token)->withHeader('X-Visitor-IP', '203.0.113.5')->postJson('/api/storefront/enquiries', $form)->assertCreated();
    expect(StorefrontEnquiry::query()->sole())->phone->toBe('+94712345678')->location_id->toBe($this->kandy->id)->ip->toBe('203.0.113.5');

    foreach (range(1, 4) as $i) {
        $this->withToken($token)->withHeader('X-Visitor-IP', '203.0.113.5')->postJson('/api/storefront/enquiries', $form);
    }
    $this->withToken($token)->withHeader('X-Visitor-IP', '203.0.113.5')->postJson('/api/storefront/enquiries', $form)->assertStatus(429);
    $this->withToken($token)->withHeader('X-Visitor-IP', '198.51.100.7')->postJson('/api/storefront/enquiries', $form)->assertCreated();
});

// ---- Screens ---------------------------------------------------------------------------------------

test('Integrations: admins only, values never sent back, one-step undo', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager)->get(route('integrations.index'))->assertForbidden();

    $this->actingAs($this->admin)->get(route('integrations.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Settings/Integrations')
        ->where('groups.0.state', 'configured')
        ->where('groups.0.credentials.1.masked', '••••')
        ->where('groups.0.credentials.0.masked', 'https://shop.test'));
    expect(json_encode($this->actingAs($this->admin)->get(route('integrations.index'))->viewData('page')))->not->toContain('cs_test')->not->toContain('hook-secret');

    $this->actingAs($this->admin)->put(route('integrations.update', 'WOO_STORE_URL'), ['value' => 'http://insecure.test'])->assertSessionHasErrors('value');
    $this->actingAs($this->admin)->put(route('integrations.update', 'WOO_CONSUMER_SECRET'), ['value' => 'cs_new_secret_value_123'])->assertSessionHasNoErrors();
    expect(Integrations::get('WOO_CONSUMER_SECRET'))->toBe('cs_new_secret_value_123')
        ->and(IntegrationCredential::query()->where('key_name', 'WOO_CONSUMER_SECRET')->value('value'))->toBe('cs_new_secret_value_123');
    $this->actingAs($this->admin)->post(route('integrations.undo', 'WOO_CONSUMER_SECRET'));
    expect(Integrations::get('WOO_CONSUMER_SECRET'))->toBe('cs_test');
});

test('Online store page: managers see status and orders needing attention, settings are admin-only', function () {
    $manager = User::factory()->manager()->create();
    $manager->assignLocations($this->kandy->id);
    app(OrderIntake::class)->process(wooOrder(['line_items' => [['name' => 'Mystery', 'sku' => 'X-1', 'quantity' => 1, 'total' => '100.00', 'total_tax' => '0']]]));

    $this->actingAs($manager)->get(route('online.index'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/Online/Index')->where('settings', null)->has('attention', 1)->where('status.configured', true));
    $this->actingAs($manager)->put(route('online.settings'), ['web_reserve_at' => 'stock', 'woo_paid_statuses' => ['processing']])->assertForbidden();
    $this->actingAs(User::factory()->cashier()->create())->get(route('online.index'))->assertForbidden();

    $this->actingAs($this->admin)->put(route('online.settings'), [
        'online_stock_locations' => [$this->kandy->id], 'web_fulfilment_location_id' => $this->kandy->id, 'web_reserve_at' => 'stock',
        'woo_sync_prices' => false, 'woo_paid_statuses' => ['processing', 'completed'], 'woo_backorder_text' => '3–4 weeks',
    ])->assertSessionHasNoErrors();
    expect(CompanySetting::current()->fresh()->onlineLocationIds())->toBe([$this->kandy->id]);
});

test('the WordPress plugin zip is built with the brand credit', function () {
    $this->artisan('woo:plugin-package')->assertSuccessful();
    $zip = new ZipArchive;
    $zip->open(WooPluginPackage::zipPath());
    expect($zip->getFromName('mvrk-woo-connector/includes/brand.php'))->toContain("'credit' => true")
        ->and($zip->locateName('mvrk-woo-connector/mvrk-woo-connector.php'))->not->toBeFalse();
    $zip->close();

    $this->actingAs($this->admin)->get(route('online.plugin'))->assertOk();
});
