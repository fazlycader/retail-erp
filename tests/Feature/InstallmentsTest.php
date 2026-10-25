<?php

use App\Enums\PaymentMethodCode;
use App\Enums\Role;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentLateFee;
use App\Models\InstallmentPlan;
use App\Models\InstallmentReminder;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Installments\InstallmentService;
use Carbon\CarbonImmutable;

function installmentFixture(array $options = []): array
{
    $location = Location::query()->create([
        'name' => 'Installment Showroom',
        'code' => $options['location_code'] ?? 'INS',
        'type' => 'showroom',
        'is_active' => true,
    ]);
    $locationCode = $options['location_code'] ?? 'INS';
    $manager = User::factory()->create([
        'name' => 'Installment Manager',
        'role' => Role::Manager,
        'pin' => '1234',
        'is_active' => true,
    ]);
    $manager->assignLocations($location->id);
    $customer = Customer::query()->create([
        'name' => 'Anushka Customer',
        'phone' => $options['phone'] ?? '+94771112233',
        'nic' => '901234567V',
        'credit_limit' => 5000000,
        'is_blacklisted' => false,
    ]);
    $settings = CompanySetting::current();
    $settings->update([
        'require_shift_for_payments' => false,
        'installment_agreement_terms' => 'The customer agrees to pay each scheduled amount by its due date.',
        'installment_tax_treatment' => $options['tax_treatment'] ?? 'non_taxable',
        'installment_early_settlement_method' => $options['early_settlement_method'] ?? 'none',
        'installment_charge_rebate_bp' => $options['charge_rebate_bp'] ?? null,
        'installment_ownership_until_paid' => false,
        'vat_registered' => $options['vat_registered'] ?? false,
        'installment_reminder_stages' => ['before:1', 'overdue:1', 'overdue:7', 'overdue:30'],
        'sms_enabled' => false,
    ]);
    $plan = InstallmentPlan::query()->create([
        'name' => 'Test Plan',
        'terms' => [3],
        'minimum_down_payment_bp' => 0,
        'charge_method' => 'flat_bp',
        'flat_bp' => 1000,
        'document_fee_cents' => 0,
        'late_fee_method' => $options['late_fee_method'] ?? 'fixed',
        'late_fee_cents' => $options['late_fee_cents'] ?? 500,
        'late_fee_bp' => $options['late_fee_bp'] ?? null,
        'grace_days' => 0,
        'is_active' => true,
    ]);
    $order = SalesOrder::query()->create([
        'number' => "SO-{$locationCode}-000001",
        'invoice_number' => "INV-{$locationCode}-000001",
        'customer_id' => $customer->id,
        'location_id' => $location->id,
        'salesperson_id' => $manager->id,
        'status' => 'confirmed',
        'grand_total' => 10000,
        'paid_amount' => 0,
        'balance_due' => 10000,
        'confirmed_at' => now(),
    ]);

    return compact('location', 'manager', 'customer', 'settings', 'plan', 'order');
}

function makeInstallmentAgreement(array $fixture, string $startDate = '2026-01-01'): InstallmentAgreement
{
    return app(InstallmentService::class)->create([
        'sales_order_id' => $fixture['order']->id,
        'plan_id' => $fixture['plan']->id,
        'term_months' => 3,
        'down_payment' => 0,
        'start_date' => $startDate,
        'signed_agreement_path' => 'installment-agreements/signed.pdf',
    ], $fixture['manager']);
}

function installmentTransfer(InstallmentAgreement $agreement, int $amount, User $actor): Payment
{
    return app(InstallmentService::class)->collect($agreement, [
        'payment_method_id' => PaymentMethod::byCode(PaymentMethodCode::BankTransfer)->id,
        'amount' => $amount,
        'reference' => 'BANK-'.uniqid(),
    ], $actor);
}

test('collections apply late fees first and then allocate to the oldest installment', function () {
    $fixture = installmentFixture();
    $agreement = makeInstallmentAgreement($fixture);
    $service = app(InstallmentService::class);

    expect($service->chargeLateFees(CarbonImmutable::parse('2026-05-01')))->toBe(3);
    $payment = installmentTransfer($agreement, 1600, $fixture['manager']);

    $allocations = $payment->allocations()->orderBy('id')->get();
    expect($allocations->pluck('kind')->all())->toBe(['late_fee', 'late_fee', 'late_fee', 'principal'])
        ->and($allocations->pluck('installment_schedule_id')->all())->toBe([
            $agreement->schedule[0]->id,
            $agreement->schedule[1]->id,
            $agreement->schedule[2]->id,
            $agreement->schedule[0]->id,
        ])
        ->and($agreement->schedule()->first()->principal_paid)->toBe(100)
        ->and($fixture['order']->fresh()->balance_due)->toBe(9900);
});

test('fixed late fees are charged once per overdue installment even if the daily command repeats', function () {
    $fixture = installmentFixture();
    makeInstallmentAgreement($fixture);
    $service = app(InstallmentService::class);

    expect($service->chargeLateFees(CarbonImmutable::parse('2026-05-01')))->toBe(3)
        ->and($service->chargeLateFees(CarbonImmutable::parse('2026-05-01')))->toBe(0)
        ->and(InstallmentLateFee::query()->count())->toBe(3)
        ->and((int) InstallmentLateFee::query()->sum('amount'))->toBe(1500);
});

test('payments allocated only to late fees are persisted without changing installment principal', function () {
    $fixture = installmentFixture();
    $agreement = makeInstallmentAgreement($fixture);
    $service = app(InstallmentService::class);
    $service->chargeLateFees(CarbonImmutable::parse('2026-05-01'));

    installmentTransfer($agreement, 1500, $fixture['manager']);
    $first = $agreement->schedule()->first();

    expect($first->late_fee_paid)->toBe(500)
        ->and($first->principal_paid)->toBe(0)
        ->and($first->amount_paid)->toBe(0)
        ->and($first->fresh()->late_fee_paid)->toBe(500);
});

test('monthly late fees are idempotent within each fee period', function () {
    $fixture = installmentFixture(['late_fee_method' => 'monthly_bp', 'late_fee_bp' => 100]);
    makeInstallmentAgreement($fixture);
    $service = app(InstallmentService::class);

    expect($service->chargeLateFees(CarbonImmutable::parse('2026-03-02')))->toBe(2)
        ->and($service->chargeLateFees(CarbonImmutable::parse('2026-03-03')))->toBe(0)
        ->and($service->chargeLateFees(CarbonImmutable::parse('2026-04-02')))->toBe(3)
        ->and(InstallmentLateFee::query()->count())->toBe(5);
});

test('due and overdue reminders are recorded and sent once per schedule stage', function () {
    $fixture = installmentFixture();
    makeInstallmentAgreement($fixture, '2026-11-01');
    $service = app(InstallmentService::class);

    expect($service->sendReminders(CarbonImmutable::parse('2026-11-30')))->toBe(1)
        ->and($service->sendReminders(CarbonImmutable::parse('2026-11-30')))->toBe(0)
        ->and($service->sendReminders(CarbonImmutable::parse('2026-12-02')))->toBe(1)
        ->and($service->sendReminders(CarbonImmutable::parse('2026-12-02')))->toBe(0)
        ->and(InstallmentReminder::query()->count())->toBe(2);
});

test('early settlement applies the configured rebate to remaining charges and closes the agreement', function () {
    $fixture = installmentFixture(['early_settlement_method' => 'charge_rebate', 'charge_rebate_bp' => 5000]);
    $agreement = makeInstallmentAgreement($fixture, '2026-11-01');
    $service = app(InstallmentService::class);
    $quote = $service->settlementQuote($agreement->load('schedule'));

    expect($quote)->toMatchArray(['principal' => 10000, 'charges' => 1000, 'rebate' => 500, 'total' => 10500]);

    $payment = $service->settleEarly($agreement, [
        'payment_method_id' => PaymentMethod::byCode(PaymentMethodCode::BankTransfer)->id,
        'amount' => $quote['total'],
        'reference' => 'EARLY-SETTLEMENT',
    ], $fixture['manager']);

    expect($payment->fresh()->amount)->toBe(10500)
        ->and($agreement->fresh()->status)->toBe('completed')
        ->and($agreement->fresh()->early_settlement_rebate)->toBe(500)
        ->and($agreement->fresh()->total_payable)->toBe(10500)
        ->and($fixture['order']->fresh()->balance_due)->toBe(0);
});

test('VAT charge treatment is calculated and stored in the agreement snapshot', function () {
    $inclusive = installmentFixture(['tax_treatment' => 'vat_inclusive', 'vat_registered' => true]);
    $inclusivePreview = app(InstallmentService::class)->preview($inclusive['order'], $inclusive['plan'], 3, 0, '2026-11-01');
    $inclusiveAgreement = makeInstallmentAgreement($inclusive);

    expect($inclusivePreview['tax_cents'])->toBe(153)
        ->and($inclusivePreview['total_payable'])->toBe(11000)
        ->and($inclusiveAgreement->tax_cents)->toBe(153)
        ->and($inclusiveAgreement->tax_treatment_snapshot)->toBe('vat_inclusive');

    $exclusive = installmentFixture(['tax_treatment' => 'vat_exclusive', 'vat_registered' => true, 'location_code' => 'INX', 'phone' => '+94770000002']);
    $exclusivePreview = app(InstallmentService::class)->preview($exclusive['order'], $exclusive['plan'], 3, 0, '2026-11-01');

    expect($exclusivePreview['tax_cents'])->toBe(180)
        ->and($exclusivePreview['total_payable'])->toBe(11180);
});

test('agreement late-fee and settlement policy snapshots are not changed by later plan edits', function () {
    $fixture = installmentFixture(['early_settlement_method' => 'charge_rebate', 'charge_rebate_bp' => 5000]);
    $agreement = makeInstallmentAgreement($fixture, '2026-11-01');
    $fixture['plan']->update(['late_fee_cents' => 900, 'grace_days' => 30]);
    $fixture['settings']->update(['installment_charge_rebate_bp' => 1000]);

    expect($agreement->fresh()->late_fee_cents_snapshot)->toBe(500)
        ->and($agreement->fresh()->grace_days_snapshot)->toBe(0)
        ->and(app(InstallmentService::class)->settlementQuote($agreement->fresh()->load('schedule'))['rebate'])->toBe(500);
});

test('agreement preview stays blocked until ownership policy is configured', function () {
    $fixture = installmentFixture();
    $fixture['settings']->update(['installment_ownership_until_paid' => null]);

    expect(fn () => app(InstallmentService::class)->preview($fixture['order'], $fixture['plan'], 3, 0, '2026-11-01'))
        ->toThrow(InvalidArgumentException::class, 'ownership');
});

test('collections screen loads with installment tables available', function () {
    $fixture = installmentFixture();

    $this->actingAs($fixture['manager'])
        ->get(route('installments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Installments/Index'));
});

test('agreement draft PDF can be generated before collecting signatures', function () {
    $fixture = installmentFixture();

    $response = $this->actingAs($fixture['manager'])->get(route('installments.draft-pdf', [
        'order_id' => $fixture['order']->id,
        'plan_id' => $fixture['plan']->id,
        'term_months' => 3,
        'down_payment_cents' => 0,
        'start_date' => '2026-11-01',
    ]));

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
});
