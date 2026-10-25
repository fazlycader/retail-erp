<?php

namespace App\Services\Cash;

use App\Enums\ChequeStatus;
use App\Enums\PaymentRecordStatus;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\Shift;

/**
 * X-report (live snapshot of an open shift) and Z-report (written once into shifts.z_report at
 * close; reprints read the stored copy, so its values never change). Amounts in cents.
 */
final class ShiftReport
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Shift $shift, string $kind = 'x'): array
    {
        $shift->loadMissing(['register:id,name', 'user:id,name', 'approvedBy:id,name', 'closedBy:id,name', 'location:id,name,code']);
        $methods = PaymentMethod::query()->get()->keyBy('id');

        $taken = Payment::query()->where('shift_id', $shift->id)->where('status', '!=', PaymentRecordStatus::Voided->value);

        $payments = (clone $taken)
            ->selectRaw('payment_method_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('payment_method_id')->get();

        $refunds = Refund::query()->where('shift_id', $shift->id)
            ->selectRaw('payment_method_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('payment_method_id')->get()->keyBy('payment_method_id');

        $method = fn ($id) => $methods->get($id);

        $byMethod = $payments->map(fn ($row) => [
            'id' => (int) $row->payment_method_id,
            'method' => $method($row->payment_method_id)?->name ?? '?',
            'code' => $method($row->payment_method_id)?->code->value,
            'count' => (int) $row->count,
            'amount' => (int) $row->amount,
            'refunds' => (int) ($refunds->get($row->payment_method_id)?->amount ?? 0),
        ])->values();

        // Refunds on methods with no payments this shift still show.
        foreach ($refunds as $methodId => $row) {
            if (! $byMethod->contains('id', (int) $methodId)) {
                $byMethod->push(['id' => (int) $methodId, 'method' => $method($methodId)?->name ?? '?', 'code' => $method($methodId)?->code->value, 'count' => 0, 'amount' => 0, 'refunds' => (int) $row->amount]);
            }
        }

        $cheques = (clone $taken)->whereNotNull('cheque_status');
        $movements = CashMovement::query()->where('shift_id', $shift->id)->with('user:id,name', 'approvedBy:id,name')->orderBy('id')->get();
        $collected = (int) $byMethod->sum('amount');
        $refunded = (int) $byMethod->sum('refunds');

        $terminal = collect($shift->terminal_totals ?? [])->map(function ($entered, $methodId) use ($byMethod) {
            $system = (int) ($byMethod->firstWhere('id', (int) $methodId)['amount'] ?? 0);

            return ['id' => (int) $methodId, 'system' => $system, 'terminal' => (int) $entered, 'difference' => (int) $entered - $system];
        })->values()->all();

        return [
            'kind' => $kind,
            'shift' => [
                'id' => $shift->id,
                'location' => $shift->location?->name,
                'register' => $shift->register?->name,
                'cashier' => $shift->user?->name,
                'business_date' => $shift->business_date->toDateString(),
                'opened_at' => $shift->opened_at->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'closed_by' => $shift->closedBy?->name,
                'status' => $shift->status->value,
            ],
            'payments' => $byMethod->all(),
            'collected' => $collected,
            'refunds' => $refunded,
            'net_collected' => $collected - $refunded,
            'orders_count' => (clone $taken)->whereNotNull('sales_order_id')->distinct()->count('sales_order_id'),
            'cheques' => [
                'count' => (clone $cheques)->count(),
                'amount' => (int) (clone $cheques)->sum('amount'),
                'bounced' => (int) (clone $cheques)->where('cheque_status', ChequeStatus::Bounced->value)->sum('amount'),
            ],
            'movements' => $movements->map(fn (CashMovement $m) => [
                'type' => $m->type->value,
                'label' => $m->type->label(),
                'category' => $m->category_name,
                'amount' => $m->amount,
                'reason' => $m->reason,
                'by' => $m->user?->name,
                'approved_by' => $m->approvedBy?->name,
                'at' => $m->created_at->toIso8601String(),
            ])->all(),
            'cash' => CashCalculator::expected($shift),
            'counted' => $shift->counted_cash,
            'variance' => $shift->variance,
            'closing_counts' => $shift->closing_counts,
            'terminal' => $terminal,
            'note' => $shift->closing_note,
            'force_close_reason' => $shift->force_close_reason,
            'approved_by' => $shift->approvedBy?->name,
            'approval_note' => $shift->approval_note,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Blind close: hide what the drawer should hold until the cashier has counted.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function blind(array $report): array
    {
        $report['cash'] = collect($report['cash'])->except('expected')->all();

        return $report;
    }
}
