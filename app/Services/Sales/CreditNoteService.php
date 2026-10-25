<?php

namespace App\Services\Sales;

use App\Enums\CreditNoteStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethodCode;
use App\Exceptions\CashActionRefused;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\Cash\ShiftService;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\DeviceRegister;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\SyncVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues credit notes (from returns or cancellations) and refunds them, and numbers refunds.
 */
class CreditNoteService
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function issue(
        int $locationId,
        ?int $customerId,
        int $amount,
        string $reason,
        User $actor,
        ?SalesOrder $order = null,
        ?SalesReturn $return = null,
    ): CreditNote {
        if ($amount <= 0) {
            throw new InvalidArgumentException('A credit note must be for more than zero.');
        }

        return DB::transaction(function () use ($locationId, $customerId, $amount, $reason, $actor, $order, $return) {
            $location = Location::query()->findOrFail($locationId);
            $settings = CompanySetting::current();
            $expiryDays = (int) ($settings->credit_note_expiry_days ?? 365);
            $seq = Counter::next(DocumentType::CreditNote->value, "location:{$locationId}");

            $note = CreditNote::query()->create([
                'number' => sprintf('%s-%s-%06d', $settings->prefixFor(DocumentType::CreditNote), $location->code, $seq),
                'share_token' => Str::random(40),
                'location_id' => $locationId,
                'customer_id' => $customerId,
                'sales_order_id' => $order?->id,
                'sales_return_id' => $return?->id,
                'amount' => $amount,
                'remaining' => $amount,
                'expires_on' => $expiryDays > 0 ? today()->addDays($expiryDays) : null,
                'status' => CreditNoteStatus::Open,
                'reason' => $reason,
                'issued_by' => $actor->id,
            ]);
            SyncVersion::bump($note);

            AuditLog::record(
                'credit_note.issued',
                $note,
                "Issued credit note {$note->number} for ".Money::fromCents($amount)->format(),
                null,
                ['amount' => $amount, 'customer_id' => $customerId, 'sales_order_id' => $order?->id],
                $actor,
            );

            return $note;
        });
    }

    /**
     * Pay out (part of) a credit note. Always needs a manager: anyone else enters a manager's PIN.
     */
    public function refund(
        CreditNote $note,
        int $methodId,
        int $amount,
        string $reason,
        User $actor,
        ?string $reference = null,
        ?int $approverId = null,
        ?string $pin = null,
    ): Refund {
        // Checked before the transaction so a refused PIN stays in the audit log.
        $approver = $actor->role->canOverride()
            ? $actor
            : ManagerApproval::verify($actor, $note->location_id, $approverId, $pin, 'credit_note.refund', $note, "refunding credit note {$note->number}");

        return DB::transaction(function () use ($note, $methodId, $amount, $reason, $actor, $reference, $approver) {
            $note = CreditNote::query()->lockForUpdate()->findOrFail($note->id);

            if (! $note->isUsable()) {
                throw new InvalidArgumentException("Credit note [{$note->number}] is used up, expired or voided.");
            }

            if ($amount <= 0 || $amount > $note->remaining) {
                throw new InvalidArgumentException('Refund between '.Money::fromCents(1)->format().' and '.Money::fromCents($note->remaining)->format().'.');
            }

            $refund = $this->recordRefund([
                'location_id' => $note->location_id,
                'customer_id' => $note->customer_id,
                'credit_note_id' => $note->id,
                'payment_method_id' => $methodId,
                'amount' => $amount,
                'reference' => $reference,
                'reason' => $reason,
                'approved_by' => $approver->id,
            ], $actor);

            $note->remaining -= $amount;
            if ($note->remaining === 0) {
                $note->status = CreditNoteStatus::Used;
            }
            $note->save();
            SyncVersion::bump($note);

            return $refund;
        });
    }

    /**
     * Number and store a refund, on the open shift. Cash refunds need one when require_shift_for_payments is on.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordRefund(array $data, User $actor): Refund
    {
        $method = PaymentMethod::query()->findOrFail((int) $data['payment_method_id']);
        if ($method->code === PaymentMethodCode::CreditNote) {
            throw new InvalidArgumentException('Issue a credit note instead of refunding to one.');
        }
        if (! $method->is_active) {
            throw new InvalidArgumentException("{$method->name} is not available.");
        }

        $location = Location::query()->findOrFail((int) $data['location_id']);
        $settings = CompanySetting::current();

        // Refunds join the open shift; cash can only leave a drawer that has one.
        $shift = $this->shifts->forTransaction($actor, $location->id, DeviceRegister::current($location->id));
        if (! $shift && $method->code === PaymentMethodCode::Cash && $settings->require_shift_for_payments) {
            throw new CashActionRefused('Open a shift first: cash refunds come out of a shift\'s drawer (Shift & cash).', 'shift_required');
        }

        $seq = Counter::next(DocumentType::Refund->value, "location:{$location->id}");

        $refund = Refund::query()->create([
            ...$data,
            'number' => sprintf('%s-%s-%06d', $settings->prefixFor(DocumentType::Refund), $location->code, $seq),
            'reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
            'refunded_by' => $actor->id,
            'shift_id' => $shift?->id,
            'business_date' => BusinessDay::current($location->id),
        ]);

        AuditLog::record(
            'refund.created',
            $refund,
            "{$method->name} refund {$refund->number} of ".Money::fromCents($refund->amount)->format(),
            null,
            ['amount' => $refund->amount, 'method' => $method->code->value, 'reason' => $refund->reason, 'approved_by' => $refund->approved_by],
            $actor,
        );

        return $refund;
    }
}
