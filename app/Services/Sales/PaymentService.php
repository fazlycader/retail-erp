<?php

namespace App\Services\Sales;

use App\Enums\ChequeStatus;
use App\Enums\CreditNoteStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReturnOutcome;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Exceptions\CashActionRefused;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DriverCollection;
use App\Models\InstallmentAgreement;
use App\Models\InstallmentPaymentAllocation;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\User;
use App\Notifications\ChequeBounced;
use App\Services\Cash\ShiftService;
use App\Services\Installments\InstallmentService;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\DayLock;
use App\Support\DeviceRegister;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\SyncVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Takes payments against sales orders (or on account), voids them and tracks cheques.
 *
 * Every write refreshes and locks the order first, so two tills paying the same order can never
 * overpay it. Cash may be over-tendered (the excess is change); every other method is refused
 * above the balance due. Cheques count as paid while pending; a bounce reverses them.
 */
class PaymentService
{
    public function __construct(private readonly ShiftService $shifts) {}

    /**
     * @param  array{
     *     payment_method_id: int,
     *     amount: int,
     *     tendered?: int|null,
     *     reference?: string|null,
     *     credit_note_id?: int|null,
     *     cheque_bank?: string|null,
     *     cheque_branch?: string|null,
     *     cheque_number?: string|null,
     *     cheque_date?: string|null,
     *     notes?: string|null,
     * }  $data
     */
    public function receive(SalesOrder $order, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Cancelled], true)) {
                throw new InvalidArgumentException("Sales order [{$order->number}] must be confirmed before taking payment.");
            }

            $payment = $this->record($order->location_id, $order->customer_id, $order, $data, $actor, $order->balance_due);

            $this->refresh($order);

            return $payment;
        });
    }

    /** Record a payment receipt against an installment agreement and cap it at the amount due. */
    public function receiveInstallment(InstallmentAgreement $agreement, array $data, User $actor, int $limit): Payment
    {
        return DB::transaction(function () use ($agreement, $data, $actor, $limit) {
            $agreement = InstallmentAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            if ($agreement->status !== 'active') {
                throw new InvalidArgumentException('Collections can only be recorded against an active agreement.');
            }

            return $this->record(
                $agreement->location_id,
                $agreement->customer_id,
                null,
                $data,
                $actor,
                $limit,
                $agreement,
            );
        });
    }

    /** Record customer money taken by a driver; it counts against the order but stays in custody until hand-over. */
    public function receiveFromDriver(Delivery $delivery, array $data, User $driver): DriverCollection
    {
        return DB::transaction(function () use ($delivery, $data, $driver) {
            abort_unless($driver->role === Role::Driver, 403);
            $delivery = Delivery::query()->with('salesOrder.location')->lockForUpdate()->findOrFail($delivery->id);
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($delivery->sales_order_id);
            $amount = (int) ($data['amount'] ?? 0);
            $methodCode = PaymentMethodCode::from($data['method']);
            if (! in_array($methodCode, [PaymentMethodCode::Cash, PaymentMethodCode::BankTransfer], true)) {
                throw new InvalidArgumentException('Drivers may collect cash or bank transfer only.');
            }
            if ($amount <= 0 || $amount > $order->balance_due || $amount > $delivery->balance_to_collect) {
                throw new InvalidArgumentException('Collection must be positive and no greater than the delivery balance or order balance.');
            }
            $reference = trim((string) ($data['reference'] ?? ''));
            if ($methodCode === PaymentMethodCode::BankTransfer && $reference === '') {
                throw new InvalidArgumentException('Enter the bank transfer reference.');
            }
            $location = $delivery->salesOrder->location;
            $prefix = CompanySetting::current()->prefixFor(DocumentType::Receipt);
            $number = sprintf('%s-%s-%06d', $prefix, $location->code, Counter::next(DocumentType::Receipt->value, "location:{$location->id}"));
            $payment = Payment::query()->create([
                'number' => $number, 'share_token' => Str::random(40), 'location_id' => $location->id,
                'customer_id' => $order->customer_id, 'sales_order_id' => $order->id,
                'payment_method_id' => PaymentMethod::byCode($methodCode)->id, 'amount' => $amount,
                'tendered' => $methodCode === PaymentMethodCode::Cash ? $amount : null, 'reference' => $reference ?: null,
                'received_by' => $driver->id, 'collected_by' => $driver->id, 'collection_status' => 'held_by_driver',
                'shift_id' => null, 'business_date' => BusinessDay::current($location->id), 'status' => PaymentRecordStatus::Completed,
                'notes' => "Collected by driver for delivery {$delivery->number}",
            ]);
            $collection = DriverCollection::query()->create([
                'payment_id' => $payment->id, 'delivery_id' => $delivery->id, 'driver_id' => $driver->id,
                'amount' => $amount, 'method' => $methodCode->value, 'reference' => $reference ?: null, 'status' => 'held_by_driver',
            ]);
            $delivery->balance_to_collect -= $amount;
            $delivery->save();
            $this->refresh($order);
            AuditLog::record('delivery.payment_collected', $payment, "{$driver->name} collected ".Money::fromCents($amount)->format()." for {$delivery->number}", null, ['delivery_id' => $delivery->id, 'method' => $methodCode->value], $driver);

            return $collection;
        });
    }

    /**
     * Go-live: an advance the customer paid in the old system, on an imported open order. Method
     * "other", flagged `imported`, dated when it was paid, on no shift (the cash is in no drawer).
     */
    public function recordImportedAdvance(SalesOrder $order, int $amount, string $paidOn, string $legacyRef, User $actor): Payment
    {
        return $this->recordExternal($order, $amount, PaymentMethodCode::Other, $paidOn, "Imported advance {$legacyRef}", 'Advance paid in the previous system (go-live import).', $actor, imported: true);
    }

    /**
     * Money received outside the tills (a web order paid online, an imported advance): no shift, no
     * cash drawer, never above the balance due. Audited as payment.external.
     */
    public function recordExternal(SalesOrder $order, int $amount, PaymentMethodCode $method, string $paidOn, string $reference, ?string $note, User $actor, bool $imported = false): Payment
    {
        return DB::transaction(function () use ($order, $amount, $method, $paidOn, $reference, $note, $actor, $imported) {
            $order = SalesOrder::query()->with('location')->lockForUpdate()->findOrFail($order->id);

            if ($amount <= 0 || $amount > $order->balance_due) {
                throw new InvalidArgumentException('The payment ('.Money::fromCents($amount)->format().') is more than the balance due ('.Money::fromCents($order->balance_due)->format().').');
            }

            $seq = Counter::next(DocumentType::Receipt->value, "location:{$order->location_id}");
            $payment = Payment::query()->create([
                'number' => sprintf('%s-%s-%06d', CompanySetting::current()->prefixFor(DocumentType::Receipt), $order->location->code, $seq),
                'share_token' => Str::random(40),
                'location_id' => $order->location_id,
                'customer_id' => $order->customer_id,
                'sales_order_id' => $order->id,
                'payment_method_id' => PaymentMethod::byCode($method)->id,
                'amount' => $amount,
                'reference' => mb_substr($reference, 0, 100),
                'received_by' => $actor->id,
                'shift_id' => null,
                'business_date' => $paidOn,
                'status' => PaymentRecordStatus::Completed,
                'imported' => $imported,
                'notes' => $note ? mb_substr($note, 0, 255) : null,
            ]);
            SyncVersion::bump($payment);
            $this->refresh($order);

            AuditLog::record($imported ? 'payment.imported' : 'payment.external', $payment, "{$method->label()} payment {$payment->number} of ".Money::fromCents($amount)->format()." for {$order->number} ({$reference})", null, ['amount' => $amount, 'reference' => $reference], $actor);

            return $payment;
        });
    }

    /**
     * A payment not tied to an order (customer deposit / account settlement).
     *
     * @param  array<string, mixed>  $data
     */
    public function receiveOnAccount(Customer $customer, int $locationId, array $data, User $actor): Payment
    {
        return DB::transaction(fn () => $this->record($locationId, $customer->id, null, $data, $actor, null));
    }

    /**
     * Void a payment. Only a manager may do it: anyone else needs a manager's PIN.
     */
    public function void(Payment $payment, User $actor, string $reason, ?int $approverId = null, ?string $pin = null): Payment
    {
        // Checked before the transaction so a refused PIN stays in the audit log.
        $approver = $actor->role->canOverride()
            ? $actor
            : ManagerApproval::verify($actor, $payment->location_id, $approverId, $pin, 'payment.void', $payment, "voiding payment {$payment->number}");

        return DB::transaction(function () use ($payment, $actor, $reason, $approver) {
            $order = $payment->sales_order_id
                ? SalesOrder::query()->lockForUpdate()->findOrFail($payment->sales_order_id)
                : null;
            $payment = Payment::query()->with('method')->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== PaymentRecordStatus::Completed) {
                throw new InvalidArgumentException("Payment [{$payment->number}] is already voided.");
            }

            DayLock::guard($payment->location_id, $payment->business_date, $actor, "voided payment {$payment->number}", $payment);

            if ($order && $order->refunded_total > 0) {
                throw new InvalidArgumentException("Sales order [{$order->number}] has refunds or credit notes from returns; void those payments through a return instead.");
            }

            if ($payment->credit_note_id) {
                $note = CreditNote::query()->lockForUpdate()->findOrFail($payment->credit_note_id);
                $note->remaining += $payment->amount;
                if ($note->status === CreditNoteStatus::Used) {
                    $note->status = CreditNoteStatus::Open;
                }
                $note->save();
                SyncVersion::bump($note);
            }

            $payment->update([
                'status' => PaymentRecordStatus::Voided,
                'voided_by' => $actor->id,
                'void_approved_by' => $approver->id,
                'voided_at' => now(),
                'void_reason' => trim($reason),
            ]);
            SyncVersion::bump($payment);

            if ($payment->installment_agreement_id) {
                app(InstallmentService::class)->paymentAllocationsFor($payment);
            }

            if ($order) {
                $this->refresh($order);
            }

            AuditLog::record(
                'payment.voided',
                $payment,
                "Voided payment {$payment->number} (".Money::fromCents($payment->amount)->format().')',
                ['status' => PaymentRecordStatus::Completed->value],
                ['status' => PaymentRecordStatus::Voided->value, 'reason' => $payment->void_reason, 'approved_by' => $approver->id],
                $actor,
            );

            return $payment;
        });
    }

    /**
     * Move a cheque along pending → deposited → cleared | bounced. A bounce reverses the payment.
     */
    public function setChequeStatus(Payment $payment, ChequeStatus $to, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $to, $actor) {
            $order = $payment->sales_order_id
                ? SalesOrder::query()->lockForUpdate()->findOrFail($payment->sales_order_id)
                : null;
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->cheque_status === null) {
                throw new InvalidArgumentException("Payment [{$payment->number}] is not a cheque.");
            }

            if ($payment->status !== PaymentRecordStatus::Completed) {
                throw new InvalidArgumentException("Payment [{$payment->number}] is voided.");
            }

            $from = $payment->cheque_status;
            if (! in_array($to, $from->next(), true)) {
                throw new InvalidArgumentException("A {$from->label()} cheque cannot be marked {$to->label()}.");
            }

            $payment->update(['cheque_status' => $to, 'cheque_status_at' => now()]);
            SyncVersion::bump($payment);

            if ($to === ChequeStatus::Bounced && $order) {
                if ($payment->installment_agreement_id) {
                    app(InstallmentService::class)->paymentAllocationsFor($payment);
                }
                $this->refresh($order);
            } elseif ($to === ChequeStatus::Bounced && $payment->installment_agreement_id) {
                app(InstallmentService::class)->paymentAllocationsFor($payment);
            }

            AuditLog::record(
                'payment.cheque_'.$to->value,
                $payment,
                "Cheque {$payment->cheque_number} on {$payment->number} marked {$to->label()}",
                ['cheque_status' => $from->value],
                ['cheque_status' => $to->value],
                $actor,
            );

            if ($to === ChequeStatus::Bounced) {
                $payment->loadMissing(['salesOrder', 'customer']);
                DB::afterCommit(fn () => Notification::send($this->managersOf($payment->location_id), new ChequeBounced($payment)));
            }

            return $payment;
        });
    }

    /**
     * Recalculate paid amount, balance and payment status from the order's payments.
     * The order must already be locked by the caller's transaction.
     */
    public function refresh(SalesOrder $order): SalesOrder
    {
        $paid = (int) Payment::query()->counted()
            ->where('sales_order_id', $order->id)
            ->whereNull('installment_agreement_id')
            ->sum('amount');
        $paid += (int) InstallmentPaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'installment_payment_allocations.payment_id')
            ->where('payments.sales_order_id', $order->id)
            ->where('payments.status', PaymentRecordStatus::Completed->value)
            ->where(fn ($query) => $query->whereNull('payments.cheque_status')->orWhere('payments.cheque_status', '!=', ChequeStatus::Bounced->value))
            ->where('installment_payment_allocations.kind', 'principal')
            ->sum('installment_payment_allocations.amount');
        $kept = $paid - $order->refunded_total;
        $net = $order->netTotal();
        $balance = max(0, $net - $kept);

        $order->paid_amount = $paid;
        $order->balance_due = $balance;
        $order->payment_status = match (true) {
            $balance === 0 => PaymentStatus::Paid,
            $kept <= 0 => PaymentStatus::Unpaid,
            $order->advance_required_cents > 0 && $kept >= $order->advance_required_cents => PaymentStatus::AdvancePaid,
            default => PaymentStatus::PartiallyPaid,
        };
        $order->advance_pending = $order->advance_required_cents > 0 && $kept < $order->advance_required_cents;
        $order->save();

        SyncVersion::bump($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(int $locationId, ?int $customerId, ?SalesOrder $order, array $data, User $actor, ?int $limit, ?InstallmentAgreement $agreement = null): Payment
    {
        $method = PaymentMethod::query()->findOrFail((int) $data['payment_method_id']);
        if (! $method->is_active) {
            throw new InvalidArgumentException("{$method->name} is not accepted any more.");
        }

        $amount = (int) ($data['amount'] ?? 0);
        $tendered = null;
        $change = 0;

        if ($method->code === PaymentMethodCode::Cash) {
            $tendered = isset($data['tendered']) && $data['tendered'] !== null ? (int) $data['tendered'] : $amount;
            if ($tendered <= 0) {
                throw new InvalidArgumentException('Enter the cash received.');
            }
            $amount = $limit === null ? $tendered : min($tendered, $limit);
            $change = $tendered - $amount;
        }

        if ($limit !== null && $limit <= 0) {
            throw new InvalidArgumentException('Nothing is due on this order.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('The payment amount must be more than zero.');
        }

        if ($limit !== null && $amount > $limit) {
            throw new InvalidArgumentException('That would overpay the order: only '.Money::fromCents($limit)->format().' is due. Only cash can be over-tendered (as change).');
        }

        $reference = isset($data['reference']) ? trim((string) $data['reference']) : '';
        if ($method->requires_reference && $reference === '') {
            throw new InvalidArgumentException("{$method->name} payments need a reference (approval code, transfer or transaction ID).");
        }

        $cheque = [];
        if ($method->code === PaymentMethodCode::Cheque) {
            foreach (['cheque_bank' => 'bank', 'cheque_number' => 'cheque number', 'cheque_date' => 'cheque date'] as $field => $label) {
                if (blank($data[$field] ?? null)) {
                    throw new InvalidArgumentException("Enter the {$label}.");
                }
            }
            $cheque = [
                'cheque_bank' => trim((string) $data['cheque_bank']),
                'cheque_branch' => filled($data['cheque_branch'] ?? null) ? trim((string) $data['cheque_branch']) : null,
                'cheque_number' => trim((string) $data['cheque_number']),
                'cheque_date' => $data['cheque_date'],
                'cheque_status' => ChequeStatus::Pending,
                'cheque_status_at' => now(),
            ];
        }

        $creditNote = null;
        if ($method->code === PaymentMethodCode::CreditNote) {
            if ($order === null && $agreement === null) {
                throw new InvalidArgumentException('A credit note can only pay for an order.');
            }
            $creditNote = $this->useCreditNote((int) ($data['credit_note_id'] ?? 0), $customerId, $amount);
            $reference = $creditNote->number;
        }

        // Every payment belongs to the cashier's (or this register's) open shift at this location.
        $shift = $this->shifts->forTransaction($actor, $locationId, DeviceRegister::current($locationId));
        if (! $shift && CompanySetting::current()->require_shift_for_payments) {
            throw new CashActionRefused('Open a shift first: payments can only be taken during a shift (Shift & cash).', 'shift_required');
        }

        $location = Location::query()->findOrFail($locationId);
        $prefix = CompanySetting::current()->prefixFor(DocumentType::Receipt);
        $seq = Counter::next(DocumentType::Receipt->value, "location:{$locationId}");

        $payment = Payment::query()->create([
            'number' => sprintf('%s-%s-%06d', $prefix, $location->code, $seq),
            'share_token' => Str::random(40),
            'location_id' => $locationId,
            'customer_id' => $customerId,
            'sales_order_id' => $order?->id ?? $agreement?->sales_order_id,
            'installment_agreement_id' => $agreement?->id,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'tendered' => $tendered,
            'change_given' => $change,
            'reference' => $reference !== '' ? $reference : null,
            'credit_note_id' => $creditNote?->id,
            ...$cheque,
            'received_by' => $actor->id,
            'shift_id' => $shift?->id,
            'business_date' => BusinessDay::current($locationId),
            'status' => PaymentRecordStatus::Completed,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ]);
        SyncVersion::bump($payment);

        if ($creditNote && $order) {
            SalesReturn::query()
                ->whereKey($creditNote->sales_return_id)
                ->where('outcome', ReturnOutcome::Exchange->value)
                ->whereNull('exchange_order_id')
                ->update(['exchange_order_id' => $order->id]);
        }

        AuditLog::record(
            'payment.received',
            $payment,
            "{$method->name} payment {$payment->number} of ".Money::fromCents($amount)->format().($order ? " for {$order->number}" : ' on account'),
            null,
            ['amount' => $amount, 'method' => $method->code->value, 'sales_order_id' => $order?->id, 'change_given' => $change],
            $actor,
        );

        return $payment;
    }

    private function useCreditNote(int $creditNoteId, ?int $customerId, int $amount): CreditNote
    {
        $note = CreditNote::query()->lockForUpdate()->find($creditNoteId);

        if (! $note) {
            throw new InvalidArgumentException('Choose the credit note to use.');
        }

        if (! $note->isUsable()) {
            throw new InvalidArgumentException("Credit note [{$note->number}] is ".($note->isExpired() ? 'expired' : 'used up or voided').'.');
        }

        if ($note->customer_id && $customerId && $note->customer_id !== $customerId) {
            throw new InvalidArgumentException("Credit note [{$note->number}] belongs to another customer.");
        }

        if ($amount > $note->remaining) {
            throw new InvalidArgumentException("Credit note [{$note->number}] only has ".Money::fromCents($note->remaining)->format().' left.');
        }

        $note->remaining -= $amount;
        if ($note->remaining === 0) {
            $note->status = CreditNoteStatus::Used;
        }
        $note->save();
        SyncVersion::bump($note);

        return $note;
    }

    /**
     * @return Collection<int, User>
     */
    private function managersOf(int $locationId)
    {
        return User::query()->active()
            ->where(fn ($q) => $q->where('role', Role::Admin->value)
                ->orWhere(fn ($q) => $q->where('role', Role::Manager->value)->atLocation($locationId)))
            ->get();
    }
}
