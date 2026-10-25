<?php

namespace App\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\ChequeStatus;
use App\Enums\PaymentRecordStatus;
use App\Exceptions\CashActionRefused;
use App\Models\AuditLog;
use App\Models\BankDeposit;
use App\Models\CashMovement;
use App\Models\CashMovementCategory;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Sales\PaymentService;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * Bank deposits: cash from the location's safe (what cash drops put there, less earlier safe
 * deposits) or straight out of an open shift's drawer (recorded as a cash drop on that shift),
 * plus pending cheques deposited in bulk on the same slip (each one marked deposited).
 */
class BankDepositService
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly PaymentService $payments,
    ) {}

    /** Cash in the location's safe: drops to the safe less cash already deposited from it. */
    public function safeBalance(int $locationId): int
    {
        $dropped = (int) CashMovement::query()->where('location_id', $locationId)
            ->where('type', CashMovementType::CashDrop->value)
            ->whereNull('bank_deposit_id')
            ->sum('amount');

        $deposited = (int) BankDeposit::query()->where('location_id', $locationId)->where('source', 'safe')->sum('cash_amount');

        return $dropped - $deposited;
    }

    /**
     * @param  array{source: string, shift_id?: int|null, cash_amount: int, cheque_ids?: array<int, int>, bank_account: string, slip_reference: string, deposited_on: string, note?: string|null}  $data
     */
    public function record(int $locationId, array $data, User $by): BankDeposit
    {
        $cash = max(0, (int) $data['cash_amount']);
        $chequeIds = array_values(array_unique(array_map('intval', $data['cheque_ids'] ?? [])));

        if ($cash === 0 && $chequeIds === []) {
            throw new CashActionRefused('Enter the cash amount or pick the cheques on this slip.');
        }

        return DB::transaction(function () use ($locationId, $data, $by, $cash, $chequeIds) {
            $shift = null;

            if ($cash > 0 && $data['source'] === 'shift') {
                $shift = Shift::query()->where('location_id', $locationId)->open()->find($data['shift_id'] ?? 0);
                if (! $shift) {
                    throw new CashActionRefused('Choose an open shift at this location.');
                }
            } elseif ($cash > 0 && $cash > $this->safeBalance($locationId)) {
                throw new CashActionRefused('That is more cash than the safe holds ('.money($this->safeBalance($locationId))->format().').');
            }

            $cheques = Payment::query()->whereIn('id', $chequeIds)->where('location_id', $locationId)
                ->where('status', PaymentRecordStatus::Completed->value)
                ->where('cheque_status', ChequeStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            if ($cheques->count() !== count($chequeIds)) {
                throw new CashActionRefused('Some of those cheques are not pending at this location any more. Refresh and try again.', 'conflict');
            }

            $deposit = new BankDeposit;
            $deposit->forceFill([
                'location_id' => $locationId,
                'source' => $data['source'] === 'shift' && $cash > 0 ? 'shift' : 'safe',
                'shift_id' => $shift?->id,
                'bank_account' => trim($data['bank_account']),
                'slip_reference' => trim($data['slip_reference']),
                'deposited_on' => $data['deposited_on'],
                'cash_amount' => $cash,
                'cheque_amount' => (int) $cheques->sum('amount'),
                'cheque_count' => $cheques->count(),
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'user_id' => $by->id,
                'business_date' => BusinessDay::current($locationId),
            ])->save();

            if ($shift) {
                $this->shifts->movement(
                    $shift,
                    $by,
                    CashMovementType::CashDrop,
                    $cash,
                    CashMovementCategory::query()->where('name', 'Bank deposit')->value('id'),
                    "Bank deposit, slip {$deposit->slip_reference}",
                    $by->role->canOverride() ? $by : null,
                    $deposit->id,
                );
            }

            foreach ($cheques as $cheque) {
                $this->payments->setChequeStatus($cheque, ChequeStatus::Deposited, $by);
            }
            Payment::query()->whereIn('id', $cheques->pluck('id'))->update(['bank_deposit_id' => $deposit->id]);

            AuditLog::record(
                'bank_deposit.recorded',
                $deposit,
                "Bank deposit {$deposit->slip_reference}: cash ".money($cash)->format()." ({$deposit->source}), {$deposit->cheque_count} cheque(s) ".money($deposit->cheque_amount)->format(),
                null,
                ['cash' => $cash, 'cheques' => $cheques->pluck('id')->all(), 'source' => $deposit->source, 'shift_id' => $shift?->id],
                $by,
            );

            return $deposit;
        });
    }
}
