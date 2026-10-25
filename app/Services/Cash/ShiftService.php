<?php

namespace App\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\ShiftStatus;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\CashActionRefused;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashMovementCategory;
use App\Models\CompanySetting;
use App\Models\DayClose;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use App\Support\BusinessDay;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opening, cash movements, closing (blind count, variance, approval) and force-closing shifts.
 * One open shift per register and per cashier, enforced by unique open_register_id / open_user_id.
 * Manager PINs are verified by the caller, before these run (so a refused PIN stays audited).
 */
class ShiftService
{
    /** The cashier's open shift, if any (at any location). */
    public function openFor(User $user): ?Shift
    {
        return Shift::query()->where('open_user_id', $user->id)->first();
    }

    /**
     * Which shift a payment or refund taken by $user at $locationId belongs to: their own open
     * shift there, else the open shift on this device's register (which must be at that location).
     */
    public function forTransaction(User $user, int $locationId, ?int $registerId): ?Shift
    {
        $own = $this->openFor($user);

        if ($own && $own->location_id === $locationId) {
            return $own;
        }

        return $registerId
            ? Shift::query()->where('open_register_id', $registerId)->where('location_id', $locationId)->first()
            : null;
    }

    public function open(Register $register, User $user, ?array $counts, ?int $total = null): Shift
    {
        if (! $register->is_active || ! $user->worksAt($register->location_id)) {
            throw new CashActionRefused('That register is not available.');
        }

        if (DayClose::isLocked($register->location_id, BusinessDay::current($register->location_id))) {
            throw new CashActionRefused('Today\'s business day is already closed at this location.', 'day_locked');
        }

        $counts = Denominations::clean($counts);
        $float = $counts ? Denominations::total($counts) : max(0, (int) $total);

        try {
            return DB::transaction(function () use ($register, $user, $counts, $float) {
                if (Shift::query()->where('open_user_id', $user->id)->exists()) {
                    throw new CashActionRefused('You already have an open shift. Close it first.', 'conflict');
                }

                if ($open = Shift::query()->with('user:id,name')->where('open_register_id', $register->id)->first()) {
                    throw new CashActionRefused("{$register->name} already has an open shift ({$open->user->name}).", 'conflict');
                }

                $shift = new Shift;
                $shift->forceFill([
                    'location_id' => $register->location_id,
                    'register_id' => $register->id,
                    'user_id' => $user->id,
                    'business_date' => BusinessDay::current($register->location_id),
                    'status' => ShiftStatus::Open,
                    'opened_at' => now(),
                    'open_register_id' => $register->id,
                    'open_user_id' => $user->id,
                    'opening_float' => $float,
                    'opening_counts' => $counts ?: null,
                ])->save();

                AuditLog::record('shift.open', $shift, "{$user->name} opened a shift on {$register->name} with a float of ".money($float)->format(), null, ['opening_float' => $float], $user);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            // Two taps at once: the database's unique open_register_id / open_user_id decided.
            throw new CashActionRefused('A shift was just opened on this register or for you.', 'conflict');
        }
    }

    public function movement(Shift $shift, User $by, CashMovementType $type, int $amount, ?int $categoryId, string $reason, ?User $approver = null, ?int $bankDepositId = null): CashMovement
    {
        if ($amount <= 0) {
            throw new CashActionRefused('Enter an amount.');
        }

        $threshold = (int) (CompanySetting::current()->payout_approval_threshold ?? 500000);

        if ($type === CashMovementType::PayOut && $amount > $threshold && ! $by->role->canOverride() && ! $approver) {
            throw new ApprovalRefused('Pay-outs over '.money($threshold)->format().' need a manager\'s PIN.', 'approval_required');
        }

        $category = $categoryId ? CashMovementCategory::query()->where('is_active', true)->find($categoryId) : null;

        if ($categoryId && ! $category) {
            throw new CashActionRefused('That category is not available.');
        }

        return DB::transaction(function () use ($shift, $by, $type, $amount, $category, $reason, $approver, $bankDepositId) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $shift->isOpen()) {
                throw new CashActionRefused('This shift is closed.', 'conflict');
            }

            if ($type !== CashMovementType::PayIn && $amount > CashCalculator::expected($shift)['expected']) {
                throw new CashActionRefused('That is more cash than should be in the drawer.');
            }

            $movement = new CashMovement;
            $movement->forceFill([
                'location_id' => $shift->location_id,
                'shift_id' => $shift->id,
                'type' => $type,
                'amount' => $amount,
                'category_id' => $category?->id,
                'category_name' => $category?->name,
                'reason' => $reason,
                'user_id' => $by->id,
                'approved_by' => $approver?->id,
                'bank_deposit_id' => $bankDepositId,
                'business_date' => BusinessDay::current($shift->location_id),
                'created_at' => now(),
            ])->save();

            $shift->save(); // new sync_version for screens
            AuditLog::record('shift.'.$type->value, $shift, "{$type->label()} ".money($amount)->format().($category ? " ({$category->name})" : '')." · {$reason}".($approver ? " · approved by {$approver->name}" : ''), null, ['movement_id' => $movement->id, 'amount' => $amount, 'approved_by' => $approver?->id], $by);

            return $movement;
        });
    }

    /**
     * Close with a (blind) count. Variance within the threshold → approved; above it → closed
     * and waiting for a manager, unless $approver (a manager, PIN already checked) signs it off now.
     *
     * @param  array<int|string, int>|null  $terminalTotals  payment_method_id => cents from the card terminal's settlement slip
     */
    public function close(Shift $shift, User $by, ?array $counts, ?int $countedTotal, ?array $terminalTotals, ?string $note, ?User $approver = null, ?string $forceReason = null): Shift
    {
        return DB::transaction(function () use ($shift, $by, $counts, $countedTotal, $terminalTotals, $note, $approver, $forceReason) {
            $shift = Shift::query()->with('user:id,name')->lockForUpdate()->findOrFail($shift->id);

            if (! $shift->isOpen()) {
                throw new CashActionRefused('This shift is already closed.', 'conflict');
            }

            $counts = Denominations::clean($counts);
            $counted = $counts ? Denominations::total($counts) : max(0, (int) $countedTotal);
            $expected = CashCalculator::expected($shift)['expected'];
            $variance = $counted - $expected;
            $threshold = (int) (CompanySetting::current()->variance_approval_threshold ?? 50000);
            $needsApproval = abs($variance) > $threshold;

            if ($needsApproval && $approver && blank($note)) {
                throw new CashActionRefused('Add a note explaining the difference.', 'note_required');
            }

            $approved = ! $needsApproval || $approver;

            $shift->forceFill([
                'status' => $approved ? ShiftStatus::Approved : ShiftStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'open_register_id' => null,
                'open_user_id' => null,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => $variance,
                'closing_counts' => $counts ?: null,
                'terminal_totals' => $terminalTotals ? array_map('intval', array_filter($terminalTotals, fn ($v) => $v !== null && $v !== '')) : null,
                'closing_note' => $note,
                'force_close_reason' => $forceReason,
                'approved_by' => $needsApproval ? $approver?->id : null,
                'approved_at' => $needsApproval && $approver ? now() : null,
                'approval_note' => $needsApproval && $approver ? $note : null,
            ])->save();

            // Frozen: the Z-report is never recalculated.
            $shift->forceFill(['z_report' => ShiftReport::build($shift->fresh(), 'z')])->save();

            AuditLog::record(
                $forceReason ? 'shift.force_close' : 'shift.close',
                $shift,
                ($forceReason ? "Force-closed {$shift->user?->name}'s shift: {$forceReason}. " : '').'Expected '.money($expected)->format().', counted '.money($counted)->format().', '.($variance === 0 ? 'no difference' : ($variance < 0 ? 'short ' : 'over ').money(abs($variance))->format()),
                ['status' => 'open'],
                ['status' => $shift->status->value, 'expected' => $expected, 'counted' => $counted, 'variance' => $variance, 'approved_by' => $approver?->id],
                $by,
            );

            return $shift;
        });
    }

    public function approve(Shift $shift, User $manager, string $note): Shift
    {
        return DB::transaction(function () use ($shift, $manager, $note) {
            $shift = Shift::query()->with('user:id,name')->lockForUpdate()->findOrFail($shift->id);

            if ($shift->status !== ShiftStatus::Closed) {
                throw new CashActionRefused($shift->isOpen() ? 'Close the shift first.' : 'This shift is already approved.', 'conflict');
            }

            $shift->forceFill(['status' => ShiftStatus::Approved, 'approved_by' => $manager->id, 'approved_at' => now(), 'approval_note' => $note])->save();

            // The frozen report gets only the approval stamp, never new figures.
            $report = $shift->z_report;
            $report['approved_by'] = $manager->name;
            $report['approval_note'] = $note;
            $report['shift']['status'] = ShiftStatus::Approved->value;
            $shift->forceFill(['z_report' => $report])->save();

            AuditLog::record('shift.approve', $shift, 'Approved a variance of '.money($shift->variance)->format()." on {$shift->user?->name}'s shift: {$note}", ['status' => 'closed'], ['status' => 'approved', 'note' => $note], $manager);

            return $shift;
        });
    }
}
