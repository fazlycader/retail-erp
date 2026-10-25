<?php

namespace App\Services\Cash;

use App\Enums\PaymentMethodCode;
use App\Enums\PaymentRecordStatus;
use App\Models\CashMovement;
use App\Models\DriverCollection;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shift;

/**
 * Expected cash in a shift's drawer:
 *
 *   expected = opening float
 *            + cash payments   (payments.amount on the cash method, not voided. Already net of
 *                               change: amount = what was applied, tendered − change)
 *            + pay-ins
 *            − cash refunds    (refunds paid out in cash during this shift)
 *            − pay-outs
 *            − cash drops      (to the safe, or straight to the bank)
 *
 * Step 12 adds cash handed over by delivery drivers to this sum.
 * Cash received (tendered) and change given are for information: received − change = cash payments.
 */
final class CashCalculator
{
    /**
     * @return array{opening_float: int, cash_payments: int, cash_received: int, change_given: int,
     *               cash_refunds: int, pay_ins: int, pay_outs: int, cash_drops: int, driver_cash: int, expected: int}
     */
    public static function expected(Shift $shift): array
    {
        $cash = Payment::query()->where('shift_id', $shift->id)
            ->whereNull('collection_status')
            ->where('status', '!=', PaymentRecordStatus::Voided->value)
            ->whereHas('method', fn ($q) => $q->where('code', PaymentMethodCode::Cash->value))
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(COALESCE(tendered, amount)), 0) AS tendered, COALESCE(SUM(change_given), 0) AS change_given')
            ->first();

        $refunds = (int) Refund::query()->where('shift_id', $shift->id)
            ->whereHas('method', fn ($q) => $q->where('code', PaymentMethodCode::Cash->value))
            ->sum('amount');

        $driverCash = (int) DriverCollection::query()->where('shift_id', $shift->id)
            ->where('status', 'handed_over')->where('method', 'cash')->sum('amount');

        $movements = CashMovement::query()->where('shift_id', $shift->id)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $parts = [
            'opening_float' => (int) $shift->opening_float,
            'cash_payments' => (int) $cash->amount,
            'cash_received' => (int) $cash->tendered,
            'change_given' => (int) $cash->change_given,
            'cash_refunds' => $refunds,
            'pay_ins' => (int) ($movements['pay_in'] ?? 0),
            'pay_outs' => (int) ($movements['pay_out'] ?? 0),
            'cash_drops' => (int) ($movements['cash_drop'] ?? 0),
            'driver_cash' => $driverCash,
        ];

        $parts['expected'] = $parts['opening_float'] + $parts['cash_payments'] + $parts['driver_cash'] + $parts['pay_ins']
            - $parts['cash_refunds'] - $parts['pay_outs'] - $parts['cash_drops'];

        return $parts;
    }
}
