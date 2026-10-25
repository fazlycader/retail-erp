<?php

use App\Enums\ChequeStatus;
use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\ProductType;
use App\Enums\ReturnOutcome;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Enums\TransferStatus;
use App\Exceptions\CashActionRefused;
use App\Models\AuditLog;
use App\Models\BankDeposit;
use App\Models\CashMovement;
use App\Models\CashMovementCategory;
use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\DayClose;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Cash\CashCalculator;
use App\Services\Cash\ShiftService;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\PosCheckoutService;
use App\Services\Sales\ReturnService;
use App\Support\DeviceRegister;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Asia/Colombo'));

    $this->kandy = Location::query()->create(['name' => 'Kandy Showroom', 'code' => 'KDY', 'type' => 'showroom', 'is_active' => true]);
    $this->galle = Location::query()->create(['name' => 'Galle Showroom', 'code' => 'GLL', 'type' => 'showroom', 'is_active' => true]);
    $this->register = Register::query()->where('location_id', $this->kandy->id)->sole(); // "Main Counter", created with the location

    $make = function (string $name, Role $role, Location $home, string $pin) {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'pin' => $pin, 'is_active' => true]);
        $user->assignLocations($home->id);

        return $user;
    };
    $this->cashier = $make('Nimal', Role::Cashier, $this->kandy, '1111');
    $this->manager = $make('Manager Mala', Role::Manager, $this->kandy, '2468');
    $this->galleCashier = $make('Sunil', Role::Cashier, $this->galle, '3333');
    $this->galleManager = $make('Manager Gayan', Role::Manager, $this->galle, '4444');

    $category = Category::query()->create(['name' => 'Tables', 'slug' => 'tables']);
    $product = app(ProductService::class)->save(
        new Product,
        ['category_id' => $category->id, 'name' => 'Coffee Table', 'slug' => 'coffee-table', 'type' => ProductType::Stock, 'is_active' => true],
        [],
        [['value_ids' => [], 'sku' => 'TBL-COF', 'price' => 480260, 'min_price' => 400000, 'online_price' => null, 'reorder_level' => 0, 'reorder_qty' => 0, 'is_active' => true]],
    );
    $this->variant = $product->variants()->first();
    foreach ([$this->kandy, $this->galle] as $location) {
        app(StockService::class)->move(['product_variant_id' => $this->variant->id, 'location_id' => $location->id, 'type' => MovementType::Opening, 'quantity' => Quantity::fromUnits(50), 'unit_cost' => 200000, 'user_id' => $this->manager->id]);
    }

    $this->customer = Customer::query()->create(['name' => 'Kamala', 'phone' => '+94771234500']);
    $this->method = fn (PaymentMethodCode $code) => PaymentMethod::byCode($code)->id;
    $this->shifts = app(ShiftService::class);

    // The cashier's shift on Main Counter with a float of 10,000.00.
    $this->shift = $this->shifts->open($this->register, $this->cashier, null, 1000000);
});

/** A cash & carry sale of one Coffee Table (4,802.60) at Kandy. */
function cashSale($t, array $payments, ?User $by = null, string $kind = 'cash_and_carry'): SalesOrder
{
    return app(PosCheckoutService::class)->checkout([
        'customer_id' => $t->customer->id,
        'location_id' => $t->kandy->id,
        'kind' => $kind,
        'lines' => [['product_variant_id' => $t->variant->id, 'source' => 'stock', 'fulfil_location_id' => $t->kandy->id, 'quantity' => Quantity::fromUnits(1), 'unit_price' => 480260]],
    ], $payments, $by ?? $t->cashier);
}

test('every location gets a Main Counter register', function () {
    expect(Register::query()->where('location_id', $this->galle->id)->pluck('name')->all())->toBe(['Main Counter']);
});

test('one open shift per register and per cashier', function () {
    $other = User::factory()->create(['role' => Role::Cashier]);
    $other->assignLocations($this->kandy->id);
    $patio = Register::query()->create(['location_id' => $this->kandy->id, 'name' => 'Second Counter']);

    $this->actingAs($other)->postJson(route('cash.open'), ['register_id' => $this->register->id, 'total' => 0])
        ->assertStatus(409)->assertJsonPath('message', 'Main Counter already has an open shift (Nimal).');
    $this->actingAs($this->cashier)->postJson(route('cash.open'), ['register_id' => $patio->id, 'total' => 0])
        ->assertStatus(409)->assertJsonPath('message', 'You already have an open shift. Close it first.');
    $this->actingAs($other)->postJson(route('cash.open'), ['register_id' => $patio->id, 'counts' => ['500000' => 1, '10000' => 3, '100' => 4]])
        ->assertOk();

    $opened = Shift::query()->where('user_id', $other->id)->sole();
    expect($opened->opening_float)->toBe(500000 + 30000 + 400)
        ->and($opened->opening_counts)->toBe(['500000' => 1, '10000' => 3, '100' => 4])
        ->and($opened->location_id)->toBe($this->kandy->id);
});

test('payments need an open shift (when the setting is on) and go to the right shift', function () {
    $order = cashSale($this, [], $this->cashier, 'order');
    $other = User::factory()->create(['role' => Role::Cashier]);
    $other->assignLocations($this->kandy->id);
    Register::query()->create(['location_id' => $this->kandy->id, 'name' => 'Second Counter']); // two registers: no automatic pick
    $card = ['payment_method_id' => ($this->method)(PaymentMethodCode::Card), 'amount' => '100', 'reference' => 'A1'];

    // No shift of their own and no device register: refused.
    $this->actingAs($other)->post(route('sales.orders.payments.store', $order), $card)->assertSessionHasErrors(['payment' => 'Open a shift first: payments can only be taken during a shift (Shift & cash).']);

    // On a device that is "Main Counter": the payment joins that register's open shift.
    $this->actingAs($other)->withCookie(DeviceRegister::COOKIE, (string) $this->register->id)
        ->post(route('sales.orders.payments.store', $order), $card)->assertSessionHasNoErrors();
    expect(Payment::query()->latest('id')->first()->shift_id)->toBe($this->shift->id);

    // A cashier's own shift wins.
    $this->defaultCookies = [];
    $this->actingAs($this->cashier)->post(route('sales.orders.payments.store', $order), $card)->assertSessionHasNoErrors();
    expect(Payment::query()->latest('id')->first()->shift_id)->toBe($this->shift->id);

    // The POS says so too.
    $this->actingAs($other)->postJson(route('pos.checkout'), [
        'kind' => 'cash_and_carry',
        'lines' => [['product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_price' => '4802.60']],
        'payments' => [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'tendered' => '5000']],
    ])->assertStatus(422)->assertJsonPath('reason', 'shift_required');

    // Setting off: allowed without a shift (not linked).
    CompanySetting::current()->update(['require_shift_for_payments' => false]);
    $this->actingAs($other)->post(route('sales.orders.payments.store', $order), $card)->assertSessionHasNoErrors();
    expect(Payment::query()->latest('id')->first()->shift_id)->toBeNull();
});

test('expected cash: float + cash payments + pay-ins − cash refunds − pay-outs − drops', function () {
    // Cash sale 4,802.60 paid with 5,000 → change 197.40.
    $sale = cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'tendered' => 500000]]);

    // A card sale doesn't touch the drawer.
    cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Card), 'amount' => 480260, 'reference' => 'C1']]);

    // A voided cash payment doesn't count.
    $voided = cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'amount' => 50000, 'tendered' => 50000]], $this->cashier, 'order');
    app(PaymentService::class)->void($voided->payments->first(), $this->manager, 'Wrong method');

    // Cash refund of the first sale (a return), pay-in 2,000, pay-out 1,250, drop 3,000.
    app(ReturnService::class)->process($sale, [['sales_order_line_id' => $sale->lines->first()->id, 'quantity' => 1000, 'condition' => 'resaleable']], ReturnOutcome::Refund, 'Scratched', $this->cashier, ($this->method)(PaymentMethodCode::Cash));
    foreach ([['pay_in', 200000], ['pay_out', 125000], ['cash_drop', 300000]] as [$type, $amount]) {
        $this->actingAs($this->cashier)->postJson(route('cash.movement'), ['type' => $type, 'amount' => $amount, 'reason' => 'Test'])->assertOk();
    }

    expect(CashCalculator::expected($this->shift->fresh()))->toBe([
        'opening_float' => 1000000,
        'cash_payments' => 480260,
        'cash_received' => 500000,           // tendered; the voided payment is excluded
        'change_given' => 19740,
        'cash_refunds' => 480260,
        'pay_ins' => 200000,
        'pay_outs' => 125000,
        'cash_drops' => 300000,
        // 10,000 + 4,802.60 + 2,000 − 4,802.60 − 1,250 − 3,000 = 7,750.00
        'expected' => 775000,
    ]);

    $parts = CashCalculator::expected($this->shift->fresh());
    expect($parts['cash_received'] - $parts['change_given'])->toBe($parts['cash_payments']);
});

test('cash refunds need an open shift; card refunds do not', function () {
    $sale = cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'tendered' => 480260]]);
    $line = $sale->lines->first()->id;
    Register::query()->create(['location_id' => $this->kandy->id, 'name' => 'Second Counter']); // no automatic register: the manager has no shift

    expect(fn () => app(ReturnService::class)->process($sale, [['sales_order_line_id' => $line, 'quantity' => 1000, 'condition' => 'resaleable']], ReturnOutcome::Refund, 'x', $this->manager, ($this->method)(PaymentMethodCode::Cash)))
        ->toThrow(CashActionRefused::class, 'cash refunds come out of a shift');

    $return = app(ReturnService::class)->process($sale, [['sales_order_line_id' => $line, 'quantity' => 1000, 'condition' => 'resaleable']], ReturnOutcome::Refund, 'x', $this->manager, ($this->method)(PaymentMethodCode::BankTransfer), 'TRX');
    expect($return->refund->shift_id)->toBeNull();
});

test('blind close: cashiers count without seeing expected, then see the variance', function () {
    cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'tendered' => 480260]]);

    $this->actingAs($this->cashier)->get(route('cash.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Cash/Current')->where('settings.blind_close', true)->missing('report.cash.expected')->where('report.cash.cash_payments', 480260));

    // Managers always see it; with blind close off cashiers do too.
    $this->actingAs($this->manager)->get(route('cash.shifts.show', $this->shift))->assertInertia(fn (Assert $page) => $page->where('report.cash.expected', 1480260));
    CompanySetting::current()->update(['blind_close' => false]);
    $this->actingAs($this->cashier)->get(route('cash.index'))->assertInertia(fn (Assert $page) => $page->where('report.cash.expected', 1480260));
    CompanySetting::current()->update(['blind_close' => true]);

    // Counts 14,800.00 → short 2.60 (within the Rs. 500 threshold) → approved straight away.
    $this->actingAs($this->cashier)->postJson(route('cash.close'), ['counts' => ['500000' => 2, '200000' => 2, '50000' => 1, '10000' => 3]])
        ->assertOk()->assertJsonPath('shift.status', 'approved')->assertJsonPath('shift.variance', -260)->assertJsonPath('shift.expected', 1480260);

    $shift = $this->shift->fresh();
    expect($shift->counted_cash)->toBe(1480000)->and($shift->open_register_id)->toBeNull()->and($shift->z_report['kind'])->toBe('z');
});

test('a variance above the threshold needs a manager (now with a PIN, or later)', function () {
    $this->actingAs($this->cashier)->postJson(route('cash.close'), ['total' => 900000])->assertOk()->assertJsonPath('shift.status', 'closed')->assertJsonPath('shift.variance', -100000);

    $shift = $this->shift->fresh();
    $this->actingAs($this->cashier)->postJson(route('cash.shifts.approve', $shift), ['note' => 'x'])->assertForbidden();
    $this->actingAs($this->manager)->postJson(route('cash.shifts.approve', $shift), ['note' => 'Float was short this morning'])->assertOk();

    expect($shift->fresh()->status)->toBe(ShiftStatus::Approved)
        ->and($shift->fresh()->approved_by)->toBe($this->manager->id)
        ->and($shift->fresh()->z_report['approved_by'])->toBe('Manager Mala')
        ->and(AuditLog::query()->where('action', 'shift.approve')->count())->toBe(1);

    // Approve at close with a manager's PIN (and a note).
    $this->actingAs($this->cashier)->postJson(route('cash.open'), ['register_id' => $this->register->id, 'total' => 0])->assertOk();
    $this->actingAs($this->cashier)->postJson(route('cash.close'), ['total' => 80000, 'approver_id' => $this->manager->id, 'approver_pin' => '2468'])->assertStatus(422)->assertJsonPath('reason', 'note_required');
    $this->actingAs($this->cashier)->postJson(route('cash.close'), ['total' => 80000, 'note' => 'Found a deposit envelope', 'approver_id' => $this->manager->id, 'approver_pin' => '2468'])
        ->assertOk()->assertJsonPath('shift.status', 'approved')->assertJsonPath('shift.variance', 80000);
});

test('the Z-report is frozen at close', function () {
    $sale = cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'tendered' => 480260]]);
    $this->actingAs($this->cashier)->postJson(route('cash.close'), ['total' => 1480260])->assertOk();

    $shift = $this->shift->fresh();
    $frozen = $shift->z_report;

    // Later events can't change it: a payment row tampered with, changed settings.
    Payment::query()->where('shift_id', $shift->id)->update(['amount' => 1]);
    CompanySetting::current()->update(['variance_approval_threshold' => 1]);

    $this->actingAs($this->cashier)->get(route('cash.shifts.show', $shift))->assertInertia(fn (Assert $page) => $page
        ->where('report.collected', 480260)
        ->where('report.cash.expected', 1480260)
        ->where('report.variance', 0));

    expect($shift->fresh()->z_report)->toBe($frozen);

    $this->actingAs($this->cashier)->get(route('cash.shifts.print', $shift))->assertOk()->assertSee('Z-REPORT (SHIFT CLOSE)')->assertSee('LKR 4,802.60');
});

test('managers force-close a shift left open, with a reason', function () {
    $this->actingAs($this->cashier)->postJson(route('cash.shifts.force-close', $this->shift), ['reason' => 'x', 'total' => 0])->assertForbidden();
    $this->actingAs($this->manager)->postJson(route('cash.shifts.force-close', $this->shift), ['total' => 1000000])->assertStatus(422);

    $this->actingAs($this->manager)->postJson(route('cash.shifts.force-close', $this->shift), ['reason' => 'Cashier went home', 'counts' => ['500000' => 2]])
        ->assertOk()->assertJsonPath('shift.status', 'approved')->assertJsonPath('shift.force_close_reason', 'Cashier went home');

    expect(AuditLog::query()->where('action', 'shift.force_close')->sole()->user_id)->toBe($this->manager->id)
        ->and($this->shift->fresh()->closed_by)->toBe($this->manager->id);
});

test('pay-outs above the limit need a manager PIN and come with a slip', function () {
    $category = CashMovementCategory::query()->where('name', 'Supplier purchase')->sole();
    CompanySetting::current()->update(['payout_approval_threshold' => 300000]);

    $this->actingAs($this->cashier)->postJson(route('cash.movement'), ['type' => 'pay_out', 'amount' => 400000, 'category_id' => $category->id, 'reason' => 'Polish'])
        ->assertStatus(422)->assertJsonPath('reason', 'approval_required');
    $slip = $this->actingAs($this->cashier)->postJson(route('cash.movement'), ['type' => 'pay_out', 'amount' => 400000, 'category_id' => $category->id, 'reason' => 'Polish', 'approver_id' => $this->manager->id, 'approver_pin' => '2468'])
        ->assertOk()->json('slip_url');

    // More than is in the drawer can't come out.
    $this->actingAs($this->cashier)->postJson(route('cash.movement'), ['type' => 'cash_drop', 'amount' => 5000000, 'reason' => 'Bank'])->assertStatus(422);

    $movement = CashMovement::query()->sole();
    expect($movement->approved_by)->toBe($this->manager->id)->and($movement->category_name)->toBe('Supplier purchase');
    $this->actingAs($this->cashier)->get($slip)->assertOk()->assertSee('PAY-OUT')->assertSee('Manager Mala');
});

test('the X-report prints without closing anything', function () {
    $this->actingAs($this->cashier)->get(route('cash.shifts.print', $this->shift))->assertOk()->assertSee('X-REPORT (NOT CLOSED)')->assertDontSee('Expected cash');
    $this->actingAs($this->manager)->get(route('cash.shifts.print', $this->shift))->assertSee('Expected cash');

    expect($this->shift->fresh()->isOpen())->toBeTrue();
});

test('shift permissions', function () {
    $storekeeper = User::factory()->create(['role' => Role::Storekeeper]);
    $storekeeper->assignLocations($this->kandy->id);
    $managerShift = $this->shifts->open(Register::query()->create(['location_id' => $this->kandy->id, 'name' => 'Second Counter']), $this->manager, null, 0);

    $this->actingAs($storekeeper)->get(route('cash.index'))->assertForbidden();
    $this->actingAs($this->cashier)->get(route('cash.shifts.index'))->assertForbidden();
    $this->actingAs($this->cashier)->get(route('cash.shifts.show', $managerShift))->assertForbidden();
    $this->actingAs($this->cashier)->get(route('cash.shifts.show', $this->shift))->assertOk();
    $this->actingAs($this->manager)->get(route('cash.shifts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('shifts.data', 2));
    $this->actingAs($this->cashier)->get(route('cash.setup'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('cash.setup'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('settings', null));
    $this->actingAs(User::factory()->admin()->create())->get(route('cash.setup'))->assertInertia(fn (Assert $page) => $page->has('settings'));
    $this->actingAs($this->manager)->put(route('cash.setup.settings'), ['variance_approval_threshold' => '1', 'payout_approval_threshold' => '1'])->assertForbidden();
});

test('locations are isolated: a Kandy cashier or manager cannot see or close a Galle shift', function () {
    $galleShift = $this->shifts->open(Register::query()->where('location_id', $this->galle->id)->sole(), $this->galleCashier, null, 500000);

    // Kandy staff: not their location.
    $this->actingAs($this->cashier)->get(route('cash.shifts.show', $galleShift))->assertForbidden();
    $this->actingAs($this->cashier)->get(route('cash.shifts.print', $galleShift))->assertForbidden();
    $this->actingAs($this->manager)->get(route('cash.shifts.show', $galleShift))->assertForbidden();
    $this->actingAs($this->manager)->postJson(route('cash.shifts.force-close', $galleShift), ['reason' => 'x', 'total' => 0])->assertForbidden();
    $this->actingAs($this->manager)->get(route('cash.shifts.index'))->assertInertia(fn (Assert $page) => $page->has('shifts.data', 1)->where('shifts.data.0.id', $this->shift->id));

    // "Close shift" only ever closes the cashier's own shift, and a Galle register can't be opened from Kandy.
    $this->actingAs($this->cashier)->postJson(route('cash.close'), ['total' => 1000000])->assertOk();
    expect($galleShift->fresh()->isOpen())->toBeTrue();
    $this->actingAs($this->cashier)->postJson(route('cash.open'), ['register_id' => $galleShift->register_id, 'total' => 0])->assertNotFound();

    // Galle's manager can.
    $this->actingAs($this->galleManager)->postJson(route('cash.shifts.force-close', $galleShift), ['reason' => 'End of day', 'total' => 500000])->assertOk();
    $this->actingAs($this->galleManager)->get(route('cash.shifts.show', $this->shift))->assertForbidden();

    // A Kandy shift never takes a Galle payment, even for the same cashier.
    $this->galleCashier->assignLocations($this->galle->id, [$this->kandy->id]);
    $this->shifts->open($this->register, $this->galleCashier, null, 0);
    expect($this->shifts->forTransaction($this->galleCashier->fresh(), $this->galle->id, null))->toBeNull();
});

test('bank deposits take cash from the safe or a shift and mark cheques deposited in bulk', function () {
    $cheque = fn (string $no) => cashSale($this, [[
        'payment_method_id' => ($this->method)(PaymentMethodCode::Cheque), 'amount' => 480260,
        'cheque_bank' => 'HNB', 'cheque_number' => $no, 'cheque_date' => today()->toDateString(),
    ]])->payments->first();
    [$c1, $c2, $c3] = [$cheque('100001'), $cheque('100002'), $cheque('100003')];

    // 3,000 dropped into the safe.
    $this->actingAs($this->cashier)->postJson(route('cash.movement'), ['type' => 'cash_drop', 'amount' => 300000, 'reason' => 'Safe'])->assertOk();

    $slip = ['bank_account' => 'BOC Kandy', 'slip_reference' => 'S-1', 'deposited_on' => today()->toDateString()];
    $this->actingAs($this->manager)->post(route('cash.deposits.store'), [...$slip, 'source' => 'safe', 'cash_amount' => '3000.01'])->assertSessionHasErrors('deposit');
    $this->actingAs($this->manager)->post(route('cash.deposits.store'), [...$slip, 'source' => 'safe', 'cash_amount' => '3000', 'cheque_ids' => [$c1->id, $c2->id]])->assertSessionHasNoErrors();

    $deposit = BankDeposit::query()->sole();
    expect($deposit->cash_amount)->toBe(300000)
        ->and($deposit->cheque_amount)->toBe(960520)
        ->and($c1->fresh()->cheque_status)->toBe(ChequeStatus::Deposited)
        ->and($c1->fresh()->bank_deposit_id)->toBe($deposit->id)
        ->and($c3->fresh()->cheque_status)->toBe(ChequeStatus::Pending);

    // Straight from the shift: a cash drop on that shift, linked to the deposit.
    $this->actingAs($this->manager)->post(route('cash.deposits.store'), [...$slip, 'slip_reference' => 'S-2', 'source' => 'shift', 'shift_id' => $this->shift->id, 'cash_amount' => '1000', 'cheque_ids' => [$c3->id]])->assertSessionHasNoErrors();
    $drop = CashMovement::query()->latest('id')->first();
    expect($drop->bank_deposit_id)->toBe(BankDeposit::query()->latest('id')->value('id'))
        ->and($drop->category_name)->toBe('Bank deposit')
        ->and(CashCalculator::expected($this->shift->fresh())['cash_drops'])->toBe(400000);

    // A cheque can't be deposited twice.
    $this->actingAs($this->manager)->post(route('cash.deposits.store'), [...$slip, 'slip_reference' => 'S-3', 'source' => 'safe', 'cheque_ids' => [$c1->id]])->assertSessionHasErrors('deposit');
    $this->actingAs($this->cashier)->get(route('cash.deposits.index'))->assertForbidden();
});

test('end of day: open shifts block, warnings need confirming, then the day is locked', function () {
    $sale = cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'tendered' => 480260]]);
    cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cheque), 'amount' => 480260, 'cheque_bank' => 'HNB', 'cheque_number' => '9', 'cheque_date' => today()->toDateString()]]);
    Transfer::query()->create(['number' => 'TRF-1', 'from_location_id' => $this->galle->id, 'to_location_id' => $this->kandy->id, 'status' => TransferStatus::Dispatched]);
    $date = '2026-10-21';

    $this->actingAs($this->manager)->postJson(route('cash.eod.close'), ['date' => $date])->assertStatus(422)->assertJsonPath('reason', 'blocked');

    $this->actingAs($this->cashier)->postJson(route('cash.close'), ['total' => 1480260])->assertOk();

    $this->actingAs($this->manager)->get(route('cash.eod', ['date' => $date]))->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Cash/EndOfDay')
        ->where('checklist.blockers', [])
        ->where('checklist.warnings.0.key', 'unprinted_invoices')
        ->where('checklist.warnings.1.key', 'cheques_due')
        ->where('checklist.warnings.2.key', 'open_transfers')
        ->where('report.totals.invoices', 2));

    $this->actingAs($this->manager)->postJson(route('cash.eod.close'), ['date' => $date])->assertStatus(422)->assertJsonPath('reason', 'warnings');
    $this->actingAs($this->manager)->postJson(route('cash.eod.close'), ['date' => $date, 'accept_warnings' => true])->assertOk();

    $day = DayClose::query()->sole();
    expect($day->isClosed())->toBeTrue()
        ->and($day->report['totals']['net_collected'])->toBe(960520)
        ->and($day->warnings)->toHaveCount(3);

    // Locked: a manager can't void that day's payment; an admin can, and it's audited.
    $payment = $sale->payments->first();
    expect(fn () => app(PaymentService::class)->void($payment, $this->manager, 'Oops'))->toThrow(CashActionRefused::class, 'is closed');
    $admin = User::factory()->admin()->create();
    app(PaymentService::class)->void($payment, $admin, 'Correction');
    expect(AuditLog::query()->where('action', 'day.locked_edit')->count())->toBe(1);

    // No new shift on a closed day, and Galle's day is still open.
    $this->actingAs($this->cashier)->postJson(route('cash.open'), ['register_id' => $this->register->id, 'total' => 0])->assertStatus(422)->assertJsonPath('reason', 'day_locked');
    expect(DayClose::isLocked($this->galle->id, $date))->toBeFalse();
});

test('printing an invoice clears the unprinted warning', function () {
    $sale = cashSale($this, [['payment_method_id' => ($this->method)(PaymentMethodCode::Cash), 'tendered' => 480260]]);
    expect($sale->invoice_printed_at)->toBeNull();

    $this->actingAs($this->cashier)->get(route('pos.receipt', $sale))->assertOk();
    expect($sale->fresh()->invoice_printed_at)->not->toBeNull();
});
