<?php

use App\Enums\ChequeStatus;
use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\ReturnCondition;
use App\Enums\ReturnOutcome;
use App\Enums\Role;
use App\Enums\SalesOrderKind;
use App\Enums\SalesOrderStatus;
use App\Exceptions\ApprovalRefused;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Register;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\ChequeBounced;
use App\Services\Cash\ShiftService;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\PosCheckoutService;
use App\Services\Sales\ReturnService;
use App\Services\Sales\SalesOrderService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

function posSetup(): array
{
    $showroom = Location::query()->create(['name' => 'Colombo Showroom', 'code' => 'CMB', 'type' => 'showroom', 'is_active' => true]);
    $warehouse = Location::query()->create(['name' => 'Central Warehouse', 'code' => 'WH', 'type' => 'warehouse', 'is_active' => true]);

    $manager = User::factory()->create(['name' => 'Showroom Manager', 'role' => Role::Manager, 'pin' => '1234', 'is_active' => true]);
    $manager->assignLocations($showroom->id, [$warehouse->id]);

    $cashier = User::factory()->create(['name' => 'Showroom Cashier', 'role' => Role::Cashier, 'pin' => '5678', 'is_active' => true]);
    $cashier->assignLocations($showroom->id);

    $category = Category::query()->create(['name' => 'Chairs', 'slug' => 'chairs']);
    $product = app(ProductService::class)->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Oslo Dining Chair', 'slug' => 'oslo-dining-chair', 'type' => ProductType::Stock, 'is_active' => true],
        [],
        [['value_ids' => [], 'sku' => 'CHR-OSLO', 'price' => 2500000, 'min_price' => 2000000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );
    $variant = $product->variants()->first();

    app(StockService::class)->move([
        'product_variant_id' => $variant->id,
        'location_id' => $showroom->id,
        'type' => MovementType::Opening,
        'quantity' => Quantity::fromUnits(10),
        'unit_cost' => 1200000,
        'user_id' => $manager->id,
    ]);

    $customer = Customer::query()->create(['name' => 'Nimali Perera', 'phone' => '+94771112233']);

    $method = fn (PaymentMethodCode $code) => PaymentMethod::byCode($code)->id;

    // Payments need an open shift (require_shift_for_payments is on by default).
    $shift = app(ShiftService::class)->open(Register::query()->where('location_id', $showroom->id)->firstOrFail(), $cashier, null, 1000000);

    return compact('showroom', 'warehouse', 'manager', 'cashier', 'variant', 'customer', 'method', 'shift');
}

function posCart(array $s, int $units = 2, ?int $customerId = null, string $kind = 'cash_and_carry'): array
{
    return [
        'customer_id' => $customerId,
        'location_id' => $s['showroom']->id,
        'kind' => $kind,
        'lines' => [[
            'product_variant_id' => $s['variant']->id,
            'source' => 'stock',
            'fulfil_location_id' => $s['showroom']->id,
            'quantity' => Quantity::fromUnits($units),
            'unit_price' => 2500000,
        ]],
    ];
}

function onHand(int $variantId, int $locationId): int
{
    return (int) StockLevel::query()->where('product_variant_id', $variantId)->where('location_id', $locationId)->value('on_hand');
}

test('payment methods are seeded', function () {
    expect(PaymentMethod::query()->active()->pluck('code')->map->value->all())
        ->toBe(['cash', 'card', 'bank_transfer', 'cheque', 'lankaqr', 'online', 'credit_note', 'other']);
});

test('split payments across cash and card settle a cash & carry sale, give change and hand the goods over', function () {
    $s = posSetup();

    $order = app(PosCheckoutService::class)->checkout(posCart($s), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Card), 'amount' => 3000000, 'reference' => 'AUTH123'],
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'amount' => 2000000, 'tendered' => 2100000],
    ], $s['cashier']);

    expect($order->invoice_number)->toBe('INV-CMB-000001')
        ->and($order->status)->toBe(SalesOrderStatus::Completed)
        ->and($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->paid_amount)->toBe(5000000)
        ->and($order->balance_due)->toBe(0)
        ->and($order->payments)->toHaveCount(2)
        ->and($order->payments[1]->change_given)->toBe(100000)
        ->and($order->payments[1]->number)->toBe('RCT-CMB-000002')
        ->and(onHand($s['variant']->id, $s['showroom']->id))->toBe(Quantity::fromUnits(8));
});

test('a cash & carry sale that is not fully paid rolls back, invoice number included', function () {
    $s = posSetup();

    expect(fn () => app(PosCheckoutService::class)->checkout(posCart($s), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Card), 'amount' => 1000000, 'reference' => 'A1'],
    ], $s['cashier']))->toThrow(InvalidArgumentException::class, 'paid in full');

    expect(SalesOrder::query()->count())->toBe(0);

    $order = app(PosCheckoutService::class)->checkout(posCart($s, 1), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'amount' => 2500000, 'tendered' => 2500000],
    ], $s['cashier']);

    expect($order->invoice_number)->toBe('INV-CMB-000001');
});

test('overpayment is refused for everything except cash, which gives change', function () {
    $s = posSetup();
    $order = app(PosCheckoutService::class)->checkout(posCart($s, 2, $s['customer']->id, 'order'), [], $s['cashier']);
    $payments = app(PaymentService::class);

    expect($order->balance_due)->toBe(5000000);

    expect(fn () => $payments->receive($order, ['payment_method_id' => ($s['method'])(PaymentMethodCode::Card), 'amount' => 5000001, 'reference' => 'X'], $s['cashier']))
        ->toThrow(InvalidArgumentException::class, 'overpay');

    expect(fn () => $payments->receive($order, ['payment_method_id' => ($s['method'])(PaymentMethodCode::Card), 'amount' => 100000], $s['cashier']))
        ->toThrow(InvalidArgumentException::class, 'reference');

    $advance = $payments->receive($order, ['payment_method_id' => ($s['method'])(PaymentMethodCode::BankTransfer), 'amount' => 1000000, 'reference' => 'TRX-1'], $s['cashier']);
    expect($advance->salesOrder->fresh()->payment_status)->toBe(PaymentStatus::PartiallyPaid);

    $cash = $payments->receive($order, ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'amount' => 0, 'tendered' => 5000000], $s['cashier']);

    expect($cash->amount)->toBe(4000000)
        ->and($cash->change_given)->toBe(1000000)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);

    expect(fn () => $payments->receive($order, ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => 100], $s['cashier']))
        ->toThrow(InvalidArgumentException::class, 'Nothing is due');
});

test('a bounced cheque reverses the payment, reopens the balance, notifies managers and is audited', function () {
    Notification::fake();
    $s = posSetup();
    $order = app(PosCheckoutService::class)->checkout(posCart($s, 2, $s['customer']->id, 'order'), [[
        'payment_method_id' => ($s['method'])(PaymentMethodCode::Cheque),
        'amount' => 5000000,
        'cheque_bank' => 'Commercial Bank',
        'cheque_branch' => 'Kollupitiya',
        'cheque_number' => '000123',
        'cheque_date' => today()->toDateString(),
    ]], $s['cashier']);

    $cheque = $order->payments->first();
    expect($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($cheque->cheque_status)->toBe(ChequeStatus::Pending);

    $payments = app(PaymentService::class);
    $payments->setChequeStatus($cheque, ChequeStatus::Deposited, $s['manager']);
    $payments->setChequeStatus($cheque, ChequeStatus::Bounced, $s['manager']);

    $order->refresh();
    expect($order->paid_amount)->toBe(0)
        ->and($order->balance_due)->toBe(5000000)
        ->and($order->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and(AuditLog::query()->where('action', 'payment.cheque_bounced')->exists())->toBeTrue();

    Notification::assertSentTo($s['manager'], ChequeBounced::class);
    Notification::assertNotSentTo($s['cashier'], ChequeBounced::class);

    expect(fn () => $payments->setChequeStatus($cheque->fresh(), ChequeStatus::Cleared, $s['manager']))
        ->toThrow(InvalidArgumentException::class);
});

test('a cashier voids a payment only with a manager PIN', function () {
    $s = posSetup();
    $order = app(PosCheckoutService::class)->checkout(posCart($s, 1, $s['customer']->id, 'order'), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Card), 'amount' => 1000000, 'reference' => 'A9'],
    ], $s['cashier']);
    $payment = $order->payments->first();
    $payments = app(PaymentService::class);

    expect(fn () => $payments->void($payment, $s['cashier'], 'Wrong card'))->toThrow(ApprovalRefused::class);
    expect(fn () => $payments->void($payment, $s['cashier'], 'Wrong card', $s['manager']->id, '0000'))->toThrow(ApprovalRefused::class);

    $voided = $payments->void($payment, $s['cashier'], 'Wrong card', $s['manager']->id, '1234');

    expect($voided->status)->toBe(PaymentRecordStatus::Voided)
        ->and($voided->void_approved_by)->toBe($s['manager']->id)
        ->and($order->fresh()->paid_amount)->toBe(0)
        ->and(AuditLog::query()->where('action', 'payment.voided')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payment.void_denied')->exists())->toBeTrue();
});

test('returns put stock back at the chosen location in the chosen condition and refund what was paid', function () {
    $s = posSetup();
    $order = app(PosCheckoutService::class)->checkout(posCart($s, 3, $s['customer']->id), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => 7500000],
    ], $s['cashier']);
    $line = $order->lines->first();

    expect(onHand($s['variant']->id, $s['showroom']->id))->toBe(Quantity::fromUnits(7));

    $return = app(ReturnService::class)->process($order, [
        ['sales_order_line_id' => $line->id, 'quantity' => Quantity::fromUnits(1), 'condition' => ReturnCondition::Resaleable],
        ['sales_order_line_id' => $line->id, 'quantity' => Quantity::fromUnits(1), 'condition' => ReturnCondition::Damaged, 'restock_location_id' => $s['warehouse']->id],
    ], ReturnOutcome::Refund, 'Wobbly legs', $s['cashier'], ($s['method'])(PaymentMethodCode::Cash));

    expect($return->total)->toBe(5000000)
        ->and($return->settled_amount)->toBe(5000000)
        ->and($return->number)->toBe('SRN-CMB-000001')
        ->and(onHand($s['variant']->id, $s['showroom']->id))->toBe(Quantity::fromUnits(8))
        ->and(onHand($s['variant']->id, $s['warehouse']->id))->toBe(0)
        ->and(StockMovement::query()->where('location_id', $s['warehouse']->id)->where('type', MovementType::Damage->value)->value('quantity'))->toBe(-Quantity::fromUnits(1))
        ->and(Refund::query()->sole()->amount)->toBe(5000000)
        ->and($line->fresh()->returned_qty)->toBe(Quantity::fromUnits(2));

    $order->refresh();
    expect($order->returned_total)->toBe(5000000)
        ->and($order->balance_due)->toBe(0)
        ->and($order->payment_status)->toBe(PaymentStatus::Paid);

    expect(fn () => app(ReturnService::class)->process($order, [
        ['sales_order_line_id' => $line->id, 'quantity' => Quantity::fromUnits(2), 'condition' => 'resaleable'],
    ], ReturnOutcome::Refund, 'Again', $s['cashier'], ($s['method'])(PaymentMethodCode::Cash)))->toThrow(InvalidArgumentException::class, 'only 1');
});

test('a return after the return window needs a manager', function () {
    $s = posSetup();
    $order = app(PosCheckoutService::class)->checkout(posCart($s, 1, $s['customer']->id), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => 2500000],
    ], $s['cashier']);
    $line = $order->lines->first();
    $returns = app(ReturnService::class);

    $this->travel(8)->days();

    expect(fn () => $returns->process($order, [['sales_order_line_id' => $line->id, 'quantity' => 1000, 'condition' => 'display']], ReturnOutcome::CreditNote, 'Late', $s['cashier']))
        ->toThrow(ApprovalRefused::class);

    $return = $returns->process($order, [['sales_order_line_id' => $line->id, 'quantity' => 1000, 'condition' => 'display']], ReturnOutcome::CreditNote, 'Late', $s['cashier'], null, null, $s['manager']->id, '1234');

    expect($return->approved_by)->toBe($s['manager']->id)
        ->and($return->creditNote->amount)->toBe(2500000);
});

test('a credit note from an exchange pays for the new sale and links it', function () {
    $s = posSetup();
    $first = app(PosCheckoutService::class)->checkout(posCart($s, 2, $s['customer']->id), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Card), 'amount' => 5000000, 'reference' => 'C1'],
    ], $s['cashier']);

    $return = app(ReturnService::class)->process($first, [
        ['sales_order_line_id' => $first->lines->first()->id, 'quantity' => 1000, 'condition' => 'resaleable'],
    ], ReturnOutcome::Exchange, 'Wrong colour', $s['cashier']);

    $note = $return->creditNote;
    expect($note->remaining)->toBe(2500000)
        ->and($note->number)->toBe('CN-CMB-000001');

    $second = app(PosCheckoutService::class)->checkout(posCart($s, 2, $s['customer']->id), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::CreditNote), 'amount' => 2500000, 'credit_note_id' => $note->id],
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => 2500000],
    ], $s['cashier']);

    expect($second->payment_status)->toBe(PaymentStatus::Paid)
        ->and($note->fresh()->remaining)->toBe(0)
        ->and($return->fresh()->exchange_order_id)->toBe($second->id);

    // A used-up credit note cannot pay again; another customer's cannot be used at all.
    $other = Customer::query()->create(['name' => 'Someone Else', 'phone' => '+94772223344']);
    $third = app(PosCheckoutService::class)->checkout(posCart($s, 1, $other->id, 'order'), [], $s['cashier']);
    expect(fn () => app(PaymentService::class)->receive($third, ['payment_method_id' => ($s['method'])(PaymentMethodCode::CreditNote), 'amount' => 100, 'credit_note_id' => $note->id], $s['cashier']))
        ->toThrow(InvalidArgumentException::class);
});

test('cancelling a paid order puts the money on a credit note', function () {
    $s = posSetup();
    $order = app(PosCheckoutService::class)->checkout(posCart($s, 1, $s['customer']->id, 'order'), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => 1000000],
    ], $s['cashier']);

    app(SalesOrderService::class)->cancel($order, $s['manager'], 'Changed mind');

    $note = CreditNote::query()->sole();
    expect($note->amount)->toBe(1000000)
        ->and($note->customer_id)->toBe($s['customer']->id)
        ->and($order->fresh()->balance_due)->toBe(0);
});

test('unpaid lines can be removed from a confirmed order, paid ones cannot', function () {
    $s = posSetup();
    $cart = posCart($s, 1, $s['customer']->id, 'order');
    $cart['lines'][] = $cart['lines'][0];
    $order = app(PosCheckoutService::class)->checkout($cart, [], $s['cashier']);

    expect($order->grand_total)->toBe(5000000);

    $orders = app(SalesOrderService::class);
    $order = $orders->removeLine($order, $order->lines->last(), $s['cashier']);

    expect($order->grand_total)->toBe(2500000)
        ->and($order->lines()->count())->toBe(1)
        ->and(StockLevel::query()->where('location_id', $s['showroom']->id)->value('reserved'))->toBe(Quantity::fromUnits(1));
});

test('confirming a non cash & carry order still needs a customer', function () {
    $s = posSetup();

    expect(fn () => app(PosCheckoutService::class)->checkout(posCart($s, 1, null, 'order'), [], $s['cashier']))
        ->toThrow(InvalidArgumentException::class, 'customer');

    expect(SalesOrderKind::CashAndCarry->value)->toBe('cash_and_carry');
});

test('cost prices are absent from POS data for the cashier and present for a manager', function () {
    $s = posSetup();

    $this->actingAs($s['cashier'])
        ->get(route('pos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Pos/Index')
            ->has('paymentMethods', 8)
            ->missing('canViewCost'));

    $this->actingAs($s['cashier'])
        ->getJson(route('pos.products', ['q' => 'CHR-OSLO']))
        ->assertOk()
        ->assertJsonPath('exact', true)
        ->assertJsonPath('variants.0.available_here', '10')
        ->assertJsonMissingPath('variants.0.cost_cents');

    $this->actingAs($s['manager'])
        ->getJson(route('pos.products', ['q' => 'CHR-OSLO']))
        ->assertJsonPath('variants.0.cost_cents', 1200000);

    $order = app(PosCheckoutService::class)->checkout(posCart($s, 1), [
        ['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => 2500000],
    ], $s['cashier']);

    $this->actingAs($s['cashier'])
        ->get(route('sales.orders.show', $order))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('order.lines.0')
            ->missing('order.lines.0.unit_cost_snapshot')
            ->has('payments', 1));
});

test('the till prices the cart on the server and flags discounts that need a manager', function () {
    $s = posSetup();
    $line = ['product_variant_id' => $s['variant']->id, 'quantity' => '2', 'unit_price' => '25000.00'];

    $this->actingAs($s['cashier'])
        ->postJson(route('pos.price'), ['lines' => [$line]])
        ->assertOk()
        ->assertJsonPath('grand_total', 5000000)
        ->assertJsonPath('needs_approval', false);

    $this->actingAs($s['cashier'])
        ->postJson(route('pos.price'), ['lines' => [[...$line, 'discount_type' => 'percent', 'discount_value' => '15']]])
        ->assertJsonPath('needs_approval', true);
});

test('checkout over HTTP, then print the receipt, the PDFs and the public digital copy', function () {
    $s = posSetup();

    $response = $this->actingAs($s['cashier'])->postJson(route('pos.checkout'), [
        'kind' => 'cash_and_carry',
        'lines' => [['product_variant_id' => $s['variant']->id, 'quantity' => '1', 'unit_price' => '25000.00']],
        'payments' => [['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => '30000']],
    ])->assertOk()
        ->assertJsonPath('order.invoice_number', 'INV-CMB-000001')
        ->assertJsonPath('order.change_given', 500000)
        ->assertJsonPath('order.opens_cash_drawer', true);

    $order = SalesOrder::query()->sole();

    $this->actingAs($s['cashier'])->get($response->json('order.receipt_url'))
        ->assertOk()
        ->assertSee('INV-CMB-000001')
        ->assertSee('80mm', false);

    $pdf = $this->actingAs($s['cashier'])->get(route('sales.orders.invoice', $order))->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();

    $this->actingAs($s['cashier'])->get(route('sales.payments.pdf', $order->payments()->first()))->assertOk();
    $this->actingAs($s['cashier'])->get(route('sales.orders.delivery-note', $order))->assertOk();

    // Public digital copy: "Invoice" when not VAT registered, "Tax Invoice" when registered.
    auth()->logout();
    $this->get(route('documents.show', $order->fresh()->share_token))->assertOk()->assertSee('Invoice')->assertDontSee('Tax Invoice');

    CompanySetting::current()->update(['vat_registered' => true, 'vat_number' => '123456789-7000']);
    $this->get(route('documents.show', $order->share_token))->assertOk()->assertSee('Tax Invoice')->assertSee('123456789-7000');
    $this->get(route('documents.pdf', $order->share_token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get('/doc/'.str_repeat('a', 40))->assertNotFound();
});

test('carts can be held and resumed at the same location only', function () {
    $s = posSetup();

    $held = $this->actingAs($s['cashier'])
        ->postJson(route('pos.held.store'), ['label' => 'Mr Silva', 'cart' => ['lines' => [['product_variant_id' => $s['variant']->id, 'quantity' => '1']]]])
        ->assertOk()
        ->json('held.0');

    $this->actingAs($s['cashier'])->getJson(route('pos.held.show', $held['id']))->assertOk()->assertJsonPath('cart.lines.0.quantity', '1');

    $elsewhere = User::factory()->create(['role' => Role::Cashier]);
    $elsewhere->assignLocations($s['warehouse']->id);
    $this->actingAs($elsewhere)->getJson(route('pos.held.show', $held['id']))->assertNotFound();

    $this->actingAs($s['cashier'])->deleteJson(route('pos.held.destroy', $held['id']))->assertOk()->assertJsonCount(0, 'held');
});

test('storekeepers and accountants cannot open the till', function () {
    $s = posSetup();
    $storekeeper = User::factory()->create(['role' => Role::Storekeeper]);
    $storekeeper->assignLocations($s['showroom']->id);

    $this->actingAs($storekeeper)->get(route('pos.index'))->assertForbidden();
    $this->actingAs($storekeeper)->postJson(route('pos.checkout'), [])->assertForbidden();
});

test('an order with several partial payments shows them all on its page', function () {
    $s = posSetup();

    $this->actingAs($s['cashier'])->postJson(route('pos.checkout'), [
        'customer_id' => $s['customer']->id,
        'kind' => 'order',
        'lines' => [['product_variant_id' => $s['variant']->id, 'quantity' => '2', 'unit_price' => '25000.00']],
        'payments' => [['payment_method_id' => ($s['method'])(PaymentMethodCode::Cash), 'tendered' => '10000']],
    ])->assertOk();

    $order = SalesOrder::query()->sole();

    $this->actingAs($s['cashier'])
        ->post(route('sales.orders.payments.store', $order), ['payment_method_id' => ($s['method'])(PaymentMethodCode::Card), 'amount' => '15000', 'reference' => 'AUTH-2'])
        ->assertSessionHasNoErrors();

    $this->actingAs($s['cashier'])
        ->get(route('sales.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 2)
            ->where('payments.1.customer', 'Nimali Perera')
            ->where('order.balance_due', 2500000));
});
