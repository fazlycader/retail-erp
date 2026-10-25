<?php

namespace App\Services\Cash;

use App\Enums\ChequeStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\SalesOrderStatus;
use App\Models\BankDeposit;
use App\Models\CashMovement;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Shift;

/**
 * A location's end-of-day figures for one business date (frozen into business_days.report at close).
 * Collected = payments not voided and not bounced, less refunds, on that business date.
 */
final class DayReport
{
    /**
     * @return array<string, mixed>
     */
    public static function build(int $locationId, string $date): array
    {
        $methods = PaymentMethod::query()->get()->keyBy('id');
        $name = fn ($id) => $methods->get($id)?->name ?? '?';

        $invoices = SalesOrder::query()->where('location_id', $locationId)->whereDate('business_date', $date)
            ->whereNotNull('invoice_number')->where('status', '!=', SalesOrderStatus::Cancelled->value);

        $payments = Payment::query()->where('location_id', $locationId)->whereDate('business_date', $date)
            ->where('status', '!=', PaymentRecordStatus::Voided->value)
            ->where(fn ($q) => $q->whereNull('cheque_status')->orWhere('cheque_status', '!=', ChequeStatus::Bounced->value))
            ->selectRaw('payment_method_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('payment_method_id')->get()
            ->map(fn ($r) => ['method' => $name($r->payment_method_id), 'count' => (int) $r->count, 'amount' => (int) $r->amount])->values();

        $refunds = Refund::query()->where('location_id', $locationId)->whereDate('business_date', $date)
            ->selectRaw('payment_method_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('payment_method_id')->get()
            ->map(fn ($r) => ['method' => $name($r->payment_method_id), 'count' => (int) $r->count, 'amount' => (int) $r->amount])->values();

        $shifts = Shift::query()->where('location_id', $locationId)->whereDate('business_date', $date)
            ->with(['user:id,name', 'register:id,name'])->orderBy('id')->get();

        $movements = CashMovement::query()->where('location_id', $locationId)->whereDate('business_date', $date)
            ->selectRaw('type, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')->groupBy('type')->get()
            ->mapWithKeys(fn ($r) => [$r->type->value => ['count' => (int) $r->count, 'amount' => (int) $r->amount]]);

        $deposits = BankDeposit::query()->where('location_id', $locationId)->whereDate('business_date', $date)->get();
        $returns = SalesReturn::query()->where('location_id', $locationId)->whereDate('business_date', $date);

        $collected = (int) $payments->sum('amount');
        $refunded = (int) $refunds->sum('amount');

        return [
            'location' => Location::query()->whereKey($locationId)->value('name'),
            'business_date' => $date,
            'totals' => [
                'invoices' => (clone $invoices)->count(),
                'sales' => (int) (clone $invoices)->sum('grand_total'),
                'returns' => (int) (clone $returns)->sum('total'),
                'returns_count' => (clone $returns)->count(),
                'collected' => $collected,
                'refunds' => $refunded,
                'net_collected' => $collected - $refunded,
                'variance' => (int) $shifts->sum('variance'),
            ],
            'payments' => $payments->all(),
            'refunds' => $refunds->all(),
            'movements' => $movements->all(),
            'deposits' => [
                'count' => $deposits->count(),
                'cash' => (int) $deposits->sum('cash_amount'),
                'cheques' => (int) $deposits->sum('cheque_amount'),
            ],
            'shifts' => $shifts->map(fn (Shift $s) => [
                'id' => $s->id,
                'cashier' => $s->user?->name,
                'register' => $s->register?->name,
                'status' => $s->status->value,
                'expected' => $s->expected_cash,
                'counted' => $s->counted_cash,
                'variance' => $s->variance,
            ])->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
