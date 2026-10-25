<?php

namespace App\Services\Installments;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentContactLog;
use App\Models\InstallmentLateFee;
use App\Models\InstallmentPaymentAllocation;
use App\Models\InstallmentPlan;
use App\Models\InstallmentReminder;
use App\Models\InstallmentSchedule;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Sales\PaymentService;
use App\Services\Sms\SmsService;
use App\Support\Counter;
use App\Support\ManagerApproval;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstallmentService
{
    public function __construct(
        private readonly ScheduleCalculator $calculator,
        private readonly PaymentService $payments,
        private readonly SmsService $sms,
    ) {}

    /**
     * @return array{down_payment: int, financed_amount: int, flat_charge: int, document_fee: int, total_payable: int, schedule: array<int, array{due_date: string, principal: int, charge: int, amount_due: int}>}
     */
    public function preview(SalesOrder $order, InstallmentPlan $plan, int $term, int $downPayment, string $startDate, ?CompanySetting $policy = null): array
    {
        $this->assertPlanReady($plan);
        $settings = $policy ?? CompanySetting::current();
        $this->assertGlobalSettingsReady($settings);
        if (! in_array($term, $plan->terms ?? [], true)) {
            throw new InvalidArgumentException('Choose a term offered by this plan.');
        }

        $requiredDownPayment = $this->roundRatio($order->grand_total * (int) $plan->minimum_down_payment_bp, 10000);
        $alreadyPaid = max(0, $order->grand_total - $order->balance_due);
        if ($alreadyPaid + $downPayment < $requiredDownPayment) {
            throw new InvalidArgumentException('The down payment is below this plan’s minimum.');
        }
        if ($downPayment < 0 || $downPayment > $order->balance_due) {
            throw new InvalidArgumentException('Down payment must be between zero and the current order balance.');
        }

        $financed = $order->balance_due - $downPayment;
        if ($financed <= 0) {
            throw new InvalidArgumentException('The order has no amount left to finance.');
        }
        $flatCharge = $plan->charge_method === 'flat_bp'
            ? $this->roundRatio($financed * (int) $plan->flat_bp, 10000)
            : 0;
        $documentFee = (int) $plan->document_fee_cents;
        $baseCharges = $flatCharge + $documentFee;
        $taxRate = (int) $settings->vat_rate_bp;
        $tax = match ($settings->installment_tax_treatment) {
            'vat_inclusive' => $this->roundRatio($baseCharges * $taxRate, 10000 + $taxRate),
            'vat_exclusive' => $this->roundRatio($baseCharges * $taxRate, 10000),
            default => 0,
        };
        $charges = $baseCharges + ($settings->installment_tax_treatment === 'vat_exclusive' ? $tax : 0);
        $schedule = $this->calculator->calculate($financed, $charges, $term, $startDate);

        return [
            'down_payment' => $downPayment,
            'financed_amount' => $financed,
            'flat_charge' => $flatCharge,
            'document_fee' => $documentFee,
            'tax_cents' => $tax,
            'tax_rate_bp' => $taxRate,
            'tax_treatment' => $settings->installment_tax_treatment,
            'total_payable' => $financed + $charges,
            'schedule' => $schedule,
        ];
    }

    /**
     * @param  array{sales_order_id: int, plan_id: int, term_months: int, down_payment: int, start_date: string, signed_agreement_path: string, approver_id?: int|null, pin?: string|null, guarantors?: array<int, array{name: string, nic: string, phone: string, address: string}>}  $data
     */
    public function create(array $data, User $actor): InstallmentAgreement
    {
        $order = SalesOrder::query()->with('customer')->findOrFail($data['sales_order_id']);
        $plan = InstallmentPlan::query()->findOrFail($data['plan_id']);
        $customer = $order->customer;

        if (! $plan->is_active) {
            throw new InvalidArgumentException('Choose an active installment plan.');
        }
        if (! $customer || blank($customer->nic)) {
            throw new InvalidArgumentException('The sales order customer must have a NIC before creating an installment agreement.');
        }
        if (! $order->invoice_number || in_array($order->status->value, ['draft', 'cancelled'], true)) {
            throw new InvalidArgumentException('Only a confirmed sales order can be financed.');
        }
        if ($order->installmentAgreement()->exists()) {
            throw new InvalidArgumentException('This sales order already has an installment agreement.');
        }

        $this->assertPlanReady($plan);
        $settings = CompanySetting::current();
        $this->assertGlobalSettingsReady($settings);

        $downPayment = (int) $data['down_payment'];
        $creditLimit = (int) $customer->credit_limit;
        $exposure = $this->creditExposure($customer, $order);
        $estimated = $this->preview($order, $plan, (int) $data['term_months'], $downPayment, $data['start_date']);
        $needsApproval = $customer->is_blacklisted || $exposure + $estimated['total_payable'] > $creditLimit;
        $approver = $needsApproval
            ? ($actor->role->canOverride()
                ? $actor
                : ManagerApproval::verify($actor, $order->location_id, $data['approver_id'] ?? null, $data['pin'] ?? null, 'installment.agreement', $order, "overriding installment credit checks for {$customer->name}"))
            : null;

        return DB::transaction(function () use ($data, $actor, $plan, $settings, $downPayment, $customer, $order, $approver) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $plan = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $settings = CompanySetting::query()->lockForUpdate()->findOrFail($settings->id);
            $this->assertPlanReady($plan);
            $this->assertGlobalSettingsReady($settings);
            if (! $customer->nic || ! $order->invoice_number || in_array($order->status->value, ['draft', 'cancelled'], true)) {
                throw new InvalidArgumentException('The customer NIC and a confirmed sales order are required.');
            }
            if ($order->installmentAgreement()->exists()) {
                throw new InvalidArgumentException('This sales order already has an installment agreement.');
            }
            $this->preview($order, $plan, (int) $data['term_months'], $downPayment, $data['start_date'], $settings);

            if ($downPayment > 0) {
                $downPaymentReceipt = $this->payments->receive($order, [
                    'payment_method_id' => (int) $data['payment_method_id'],
                    'amount' => $downPayment,
                    'tendered' => $data['tendered'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'cheque_bank' => $data['cheque_bank'] ?? null,
                    'cheque_branch' => $data['cheque_branch'] ?? null,
                    'cheque_number' => $data['cheque_number'] ?? null,
                    'cheque_date' => $data['cheque_date'] ?? null,
                    'notes' => 'Installment agreement down payment',
                ], $actor);
                $downPayment = $downPaymentReceipt->amount;
                $order->refresh();
            }

            $preview = $this->preview($order, $plan, (int) $data['term_months'], 0, $data['start_date'], $settings);
            $financed = $preview['financed_amount'];
            $charges = $preview['flat_charge'] + $preview['document_fee']
                + ($settings->installment_tax_treatment === 'vat_exclusive' ? $preview['tax_cents'] : 0);
            $tax = $preview['tax_cents'];
            $totalPayable = $preview['total_payable'];
            $latestExposure = $this->creditExposure($customer, $order);
            if (($customer->is_blacklisted || $latestExposure + $totalPayable > (int) $customer->credit_limit) && ! $approver) {
                throw new InvalidArgumentException('Credit exposure changed while creating this agreement. Manager approval is required; please try again.');
            }

            $location = Location::query()->findOrFail($order->location_id);
            $number = sprintf('HP-%s-%06d', $location->code, Counter::next('installment_agreement', "location:{$location->id}"));
            $agreement = InstallmentAgreement::query()->create([
                'number' => $number,
                'location_id' => $order->location_id,
                'sales_order_id' => $order->id,
                'customer_id' => $customer->id,
                'installment_plan_id' => $plan->id,
                'created_by' => $actor->id,
                'approved_by' => $approver?->id,
                'financed_amount' => $financed,
                'down_payment' => $downPayment,
                'charges' => $charges,
                'tax_cents' => $tax,
                'tax_rate_bp_snapshot' => (int) $settings->vat_rate_bp,
                'total_payable' => $totalPayable,
                'early_settlement_method_snapshot' => $settings->installment_early_settlement_method,
                'charge_rebate_bp_snapshot' => (int) ($settings->installment_charge_rebate_bp ?? 0),
                'ownership_until_paid_snapshot' => (bool) $settings->installment_ownership_until_paid,
                'late_fee_method_snapshot' => $plan->late_fee_method,
                'late_fee_cents_snapshot' => $plan->late_fee_cents,
                'late_fee_bp_snapshot' => $plan->late_fee_bp,
                'grace_days_snapshot' => $plan->grace_days,
                'term_months' => (int) $data['term_months'],
                'start_date' => $data['start_date'],
                'status' => 'active',
                'signed_agreement_path' => $data['signed_agreement_path'],
                'agreement_terms_snapshot' => $settings->installment_agreement_terms,
                'tax_treatment_snapshot' => $settings->installment_tax_treatment,
            ]);

            foreach ($this->calculator->calculate($financed, $charges, $agreement->term_months, $agreement->start_date->toDateString()) as $row) {
                $agreement->schedule()->create($row);
            }
            foreach ($data['guarantors'] ?? [] as $guarantor) {
                $agreement->guarantors()->create($guarantor);
            }

            AuditLog::record('installment.agreement_created', null, "Created installment agreement {$agreement->number}", null, [
                'sales_order_id' => $order->id,
                'customer_id' => $customer->id,
                'financed_amount' => $financed,
                'total_payable' => $totalPayable,
                'approved_by' => $approver?->id,
            ], $actor);

            return $agreement->load(['customer', 'salesOrder', 'plan', 'schedule', 'guarantors']);
        });
    }

    public function collect(InstallmentAgreement $agreement, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($agreement, $data, $actor) {
            $agreement = InstallmentAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            if ($agreement->status !== 'active') {
                throw new InvalidArgumentException('Collections can only be recorded against an active agreement.');
            }
            $due = (int) $agreement->schedule()->sum(DB::raw('(amount_due - amount_paid) + (late_fee_charged - late_fee_paid)'));
            if ($due <= 0) {
                throw new InvalidArgumentException('Nothing is due on this agreement.');
            }

            $payment = $this->payments->receiveInstallment($agreement, $data, $actor, $due);
            $remaining = $payment->amount;
            $schedule = $agreement->schedule()->whereRaw('(amount_due > amount_paid) OR (late_fee_charged > late_fee_paid)')
                ->orderBy('due_date')->lockForUpdate()->get();

            foreach ($schedule as $line) {
                $feeDue = max(0, $line->late_fee_charged - $line->late_fee_paid);
                if ($remaining > 0 && $feeDue > 0) {
                    $allocated = min($remaining, $feeDue);
                    $this->allocate($payment, $line, 'late_fee', $allocated);
                    $line->late_fee_paid += $allocated;
                    $remaining -= $allocated;
                    $this->syncScheduleStatus($line);
                    $line->save();
                }
            }

            foreach ($schedule as $line) {
                if ($remaining <= 0) {
                    break;
                }
                $installmentDue = max(0, $line->amount_due - $line->amount_paid);
                if ($installmentDue <= 0) {
                    continue;
                }

                $principalDue = max(0, $line->principal - $line->principal_paid);
                $principalPaid = min($remaining, $principalDue);
                if ($principalPaid > 0) {
                    $this->allocate($payment, $line, 'principal', $principalPaid);
                    $line->principal_paid += $principalPaid;
                    $line->amount_paid += $principalPaid;
                    $remaining -= $principalPaid;
                }

                $chargeDue = max(0, $line->amount_due - $line->principal - ($line->amount_paid - $principalPaid));
                $chargePaid = min($remaining, $chargeDue);
                if ($chargePaid > 0) {
                    $this->allocate($payment, $line, 'charge', $chargePaid);
                    $line->amount_paid += $chargePaid;
                    $remaining -= $chargePaid;
                }

                $this->syncScheduleStatus($line);
                $line->save();
            }

            $this->updateAgreementStatus($agreement);
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($agreement->sales_order_id);
            $this->payments->refresh($order);

            return $payment;
        });
    }

    public function chargeLateFees(?CarbonImmutable $on = null): int
    {
        $today = ($on ?? CarbonImmutable::today())->startOfDay();
        $count = 0;
        $lines = InstallmentSchedule::query()->with('agreement.plan')
            ->whereHas('agreement', fn ($q) => $q->where('status', 'active'))
            ->whereRaw('amount_due > amount_paid')
            ->whereDate('due_date', '<', $today->toDateString())
            ->orderBy('due_date')->get();

        foreach ($lines as $line) {
            $agreement = $line->agreement;
            $cutoff = $line->due_date->addDays((int) $agreement->grace_days_snapshot);
            if ($today->lessThanOrEqualTo($cutoff)) {
                continue;
            }
            $monthly = $agreement->late_fee_method_snapshot === 'monthly_bp';
            $period = $monthly ? $today->format('Y-m') : 'once';
            $base = max(0, $line->amount_due - $line->amount_paid);
            $amount = $monthly
                ? $this->roundRatio($base * (int) $agreement->late_fee_bp_snapshot, 10000)
                : (int) $agreement->late_fee_cents_snapshot;
            if ($line->status !== 'overdue') {
                $line->status = 'overdue';
                $line->save();
            }
            if ($amount <= 0) {
                continue;
            }

            $inserted = DB::transaction(function () use ($line, $period, $amount, $today) {
                $locked = InstallmentSchedule::query()->lockForUpdate()->findOrFail($line->id);
                if ($locked->amount_due <= $locked->amount_paid) {
                    return false;
                }
                $inserted = InstallmentLateFee::query()->insertOrIgnore([
                    'installment_schedule_id' => $locked->id,
                    'period_key' => $period,
                    'amount' => $amount,
                    'charged_on' => $today->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted) {
                    $locked->late_fee_charged += $amount;
                    $locked->status = 'overdue';
                    $locked->save();
                }

                return (bool) $inserted;
            });
            $count += (int) $inserted;
        }

        return $count;
    }

    public function sendReminders(?CarbonImmutable $on = null): int
    {
        $today = ($on ?? CarbonImmutable::today())->startOfDay();
        $settings = CompanySetting::current();
        $stages = $settings->installment_reminder_stages ?: ['before:1', 'overdue:1', 'overdue:7', 'overdue:30'];
        $count = 0;
        $lines = InstallmentSchedule::query()->with(['agreement.customer'])
            ->whereHas('agreement', fn ($q) => $q->where('status', 'active'))
            ->whereRaw('amount_due > amount_paid')
            ->get();

        foreach ($lines as $line) {
            $daysUntil = (int) $today->diffInDays($line->due_date->startOfDay(), false);
            $daysOverdue = (int) $line->due_date->startOfDay()->diffInDays($today, false);
            $stage = null;
            foreach ($stages as $configured) {
                [$direction, $days] = array_pad(explode(':', (string) $configured, 2), 2, null);
                if (($direction === 'before' && $daysUntil === (int) $days)
                    || ($direction === 'overdue' && $daysOverdue === (int) $days)) {
                    $stage = "{$direction}:{$days}";
                    break;
                }
            }
            if (! $stage || InstallmentReminder::query()->where('installment_schedule_id', $line->id)->where('stage', $stage)->exists()) {
                continue;
            }

            $overdue = str_starts_with($stage, 'overdue:');
            $message = $line->agreement->customer->sms_opt_out ? null : $this->sms->queue(
                $overdue ? 'installment_overdue' : 'installment_due',
                $line->agreement->customer,
                [
                    'agreement' => $line->agreement->number,
                    'amount' => Money::fromCents(max(0, $line->amount_due - $line->amount_paid) + max(0, $line->late_fee_charged - $line->late_fee_paid))->format(),
                    'date' => $line->due_date->toDateString(),
                ],
                null,
                "installment:{$line->id}:{$stage}",
                $line->agreement->location_id,
            );
            InstallmentReminder::query()->create([
                'installment_schedule_id' => $line->id,
                'stage' => $stage,
                'sms_message_id' => $message?->id,
                'sent_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    public function settlementQuote(InstallmentAgreement $agreement): array
    {
        $settings = CompanySetting::current();
        $schedule = $agreement->schedule;
        $remainingPrincipal = (int) $schedule->sum(fn ($line) => max(0, $line->principal - $line->principal_paid));
        $remainingCharge = (int) $schedule->sum(fn ($line) => max(0, $line->amount_due - $line->amount_paid - ($line->principal - $line->principal_paid)));
        $remainingLateFees = (int) $schedule->sum(fn ($line) => max(0, $line->late_fee_charged - $line->late_fee_paid));
        $rebate = $agreement->early_settlement_method_snapshot === 'charge_rebate'
            ? $this->roundRatio($remainingCharge * (int) $agreement->charge_rebate_bp_snapshot, 10000)
            : 0;

        return [
            'principal' => $remainingPrincipal,
            'charges' => $remainingCharge,
            'late_fees' => $remainingLateFees,
            'rebate' => $rebate,
            'total' => max(0, $remainingPrincipal + $remainingCharge + $remainingLateFees - $rebate),
        ];
    }

    public function settleEarly(InstallmentAgreement $agreement, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($agreement, $data, $actor) {
            $agreement = InstallmentAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            if ($agreement->status !== 'active') {
                throw new InvalidArgumentException('Only active agreements can be settled.');
            }

            $quote = $this->settlementQuote($agreement->load('schedule'));
            if ((int) ($data['amount'] ?? 0) !== $quote['total']
                || ((int) ($data['tendered'] ?? $quote['total']) < $quote['total'])) {
                throw new InvalidArgumentException('Early settlement must cover the full quoted balance.');
            }
            $rebate = $quote['rebate'];
            if ($rebate > 0) {
                $taxRebate = in_array($agreement->tax_treatment_snapshot, ['vat_inclusive', 'vat_exclusive'], true)
                    ? $this->roundRatio($rebate * $agreement->tax_rate_bp_snapshot, 10000 + $agreement->tax_rate_bp_snapshot)
                    : 0;
                $remaining = $rebate;
                foreach ($agreement->schedule()->orderByDesc('due_date')->lockForUpdate()->get() as $line) {
                    $unpaidCharge = max(0, $line->amount_due - $line->amount_paid - ($line->principal - $line->principal_paid));
                    $discount = min($remaining, $unpaidCharge);
                    if ($discount <= 0) {
                        continue;
                    }
                    $line->charge -= $discount;
                    $line->amount_due -= $discount;
                    $line->save();
                    $remaining -= $discount;
                    if ($remaining === 0) {
                        break;
                    }
                }
                $agreement->charges -= $rebate;
                $agreement->tax_cents = max(0, $agreement->tax_cents - $taxRebate);
                $agreement->total_payable -= $rebate;
                $agreement->early_settlement_rebate += $rebate;
                $agreement->save();
            }

            return $this->collect($agreement, $data, $actor);
        });
    }

    public function logContact(InstallmentAgreement $agreement, array $data, User $actor): InstallmentContactLog
    {
        return $agreement->contactLogs()->create([
            'installment_schedule_id' => $data['schedule_id'] ?? null,
            'user_id' => $actor->id,
            'notes' => trim($data['notes']),
            'promise_to_pay_on' => $data['promise_to_pay_on'] ?? null,
        ]);
    }

    public function signedDocument(InstallmentAgreement $agreement): StreamedResponse
    {
        abort_unless($agreement->signed_agreement_path && Storage::disk('local')->exists($agreement->signed_agreement_path), 404);

        return Storage::disk('local')->download($agreement->signed_agreement_path, basename($agreement->signed_agreement_path));
    }

    public function updateAgreementStatus(InstallmentAgreement $agreement): void
    {
        $remaining = (int) $agreement->schedule()->sum(DB::raw('(amount_due - amount_paid) + (late_fee_charged - late_fee_paid)'));
        if ($remaining === 0 && $agreement->status === 'active') {
            $agreement->update(['status' => 'completed', 'completed_at' => today()]);
        } elseif ($remaining > 0 && $agreement->status === 'completed') {
            $agreement->update(['status' => 'active', 'completed_at' => null]);
        }
    }

    public function paymentAllocationsFor(Payment $payment): array
    {
        $allocations = $payment->installmentAgreement
            ? InstallmentPaymentAllocation::query()->where('payment_id', $payment->id)->with('schedule')->get()
            : collect();
        foreach ($allocations->groupBy('installment_schedule_id') as $scheduleAllocations) {
            $line = $scheduleAllocations->first()->schedule;
            foreach ($scheduleAllocations as $allocation) {
                match ($allocation->kind) {
                    'principal' => $line->principal_paid = max(0, $line->principal_paid - $allocation->amount),
                    'charge' => $line->amount_paid = max(0, $line->amount_paid - $allocation->amount),
                    'late_fee' => $line->late_fee_paid = max(0, $line->late_fee_paid - $allocation->amount),
                    default => null,
                };
                if ($allocation->kind === 'principal') {
                    $line->amount_paid = max(0, $line->amount_paid - $allocation->amount);
                }
            }
            $this->syncScheduleStatus($line);
            $line->save();
        }
        if ($payment->installment_agreement) {
            $this->updateAgreementStatus($payment->installmentAgreement);
        }

        return $allocations->all();
    }

    private function allocate(Payment $payment, InstallmentSchedule $line, string $kind, int $amount): void
    {
        InstallmentPaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'installment_schedule_id' => $line->id,
            'kind' => $kind,
            'amount' => $amount,
        ]);
    }

    private function syncScheduleStatus(InstallmentSchedule $line): void
    {
        $installmentPaid = $line->amount_paid >= $line->amount_due;
        $feesPaid = $line->late_fee_paid >= $line->late_fee_charged;
        $line->status = $installmentPaid && $feesPaid
            ? 'paid'
            : ($line->due_date->lt(today()) ? ($line->amount_paid > 0 ? 'partially_paid' : 'overdue') : ($line->amount_paid > 0 ? 'partially_paid' : 'due'));
    }

    private function creditExposure(Customer $customer, SalesOrder $currentOrder): int
    {
        $otherOrderBalance = (int) $customer->salesOrders()
            ->whereKeyNot($currentOrder->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDoesntHave('installmentAgreement')
            ->sum('balance_due');
        $installmentBalance = 0;
        foreach ($customer->installmentAgreements()->where('status', 'active')->with('schedule')->get() as $agreement) {
            $installmentBalance += (int) $agreement->schedule->sum(fn ($line) => max(0, $line->amount_due - $line->amount_paid + $line->late_fee_charged - $line->late_fee_paid));
        }

        return $otherOrderBalance + $installmentBalance;
    }

    private function assertPlanReady(InstallmentPlan $plan): void
    {
        if (! $plan->is_active) {
            throw new InvalidArgumentException('This installment plan is inactive.');
        }
        if (! is_array($plan->terms) || $plan->terms === [] || $plan->minimum_down_payment_bp === null
            || $plan->document_fee_cents === null || $plan->grace_days === null || $plan->late_fee_method === null) {
            throw new InvalidArgumentException('Complete all plan settings before activating or using this plan.');
        }
        if ($plan->charge_method === 'flat_bp' && $plan->flat_bp === null) {
            throw new InvalidArgumentException('Configure the plan charge before using it.');
        }
        if (($plan->late_fee_method === 'fixed' && $plan->late_fee_cents === null)
            || ($plan->late_fee_method === 'monthly_bp' && $plan->late_fee_bp === null)) {
            throw new InvalidArgumentException('Complete the plan late-fee settings before using it.');
        }
    }

    private function assertGlobalSettingsReady(CompanySetting $settings): void
    {
        if (blank($settings->installment_agreement_terms) || ! in_array($settings->installment_tax_treatment, ['non_taxable', 'vat_inclusive', 'vat_exclusive'], true)) {
            throw new InvalidArgumentException('Configure the accountant-approved agreement wording and tax treatment before activating installment plans.');
        }
        if (in_array($settings->installment_tax_treatment, ['vat_inclusive', 'vat_exclusive'], true) && ! $settings->vat_registered) {
            throw new InvalidArgumentException('VAT treatment can only be selected for a VAT-registered company.');
        }
        if (! in_array($settings->installment_early_settlement_method, ['none', 'charge_rebate'], true)
            || ($settings->installment_early_settlement_method === 'charge_rebate' && $settings->installment_charge_rebate_bp === null)) {
            throw new InvalidArgumentException('Configure the early-settlement policy before activating installment plans.');
        }
        if ($settings->installment_ownership_until_paid === null) {
            throw new InvalidArgumentException('Choose whether ownership remains with the business until the agreement is paid.');
        }
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
