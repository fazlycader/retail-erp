<?php

namespace App\Services\Reports;

use App\Enums\ChequeStatus;
use App\Enums\FulfilmentType;
use App\Enums\PaymentMethodCode;
use App\Enums\PaymentRecordStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\ShiftStatus;
use App\Enums\TransferStatus;
use App\Models\Location;
use App\Models\Payment;
use App\Models\ProductionJob;
use App\Models\Quotation;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Models\Transfer;
use App\Models\User;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;

/**
 * The owner's dashboard: today vs the same day last week per location, collected cash, gross
 * profit (view-profit only), and what needs attention. Admins see every location; managers and
 * accountants the locations they work at.
 */
class OwnerDashboard
{
    private const OPEN = [SalesOrderStatus::Confirmed, SalesOrderStatus::InProduction, SalesOrderStatus::Ready, SalesOrderStatus::PartiallyFulfilled];

    public function __construct(private SalesReader $sales, private ReportBuilder $builder, private StockReports $stock) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $ids = ReportScope::allowed($user);
        $profit = $user->can('view-profit');
        $locations = Location::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'name', 'code']);

        $rows = $locations->map(function (Location $l) use ($profit) {
            $today = BusinessDay::current($l->id);
            $lastWeek = CarbonImmutable::parse($today)->subWeek()->toDateString();
            $now = $this->builder->derived($this->sales->totals(new ReportRange([$l->id], $today, $today)));
            $before = $this->builder->derived($this->sales->totals(new ReportRange([$l->id], $lastWeek, $lastWeek)));

            return [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
                'date' => $today,
                'invoices' => $now['invoices_count'],
                'sales' => $now['sales'],
                'net_sales' => $now['net_sales'],
                'last_week' => $before['net_sales'],
                'collected' => $now['collected'] - $now['refunds'],
                'cash' => $this->cashToday($l->id, $today),
                ...($profit ? ['gross_profit' => $now['gross_profit'], 'last_week_profit' => $before['gross_profit']] : []),
            ];
        });

        $openOrders = SalesOrder::query()->whereIn('location_id', $ids)->whereIn('status', array_map(fn ($s) => $s->value, self::OPEN));
        $deliveries = (clone $openOrders)->where('fulfilment_type', FulfilmentType::Deliver->value);
        $pendingCheques = Payment::query()->whereIn('location_id', $ids)->where('status', PaymentRecordStatus::Completed->value)
            ->whereIn('cheque_status', [ChequeStatus::Pending->value, ChequeStatus::Deposited->value]);
        $followUps = Quotation::query()->whereIn('location_id', $ids)->whereIn('status', [QuotationStatus::Sent->value, QuotationStatus::Accepted->value])
            ->whereNotNull('follow_up_date')->whereDate('follow_up_date', '<=', today());
        $overdueJobs = ProductionJob::query()->overdue()->where(function ($q) use ($ids) {
            $q->whereIn('location_id', $ids)->orWhereHas('salesOrder', fn ($sq) => $sq->whereIn('location_id', $ids));
        });

        $totals = [
            'net_sales' => $rows->sum('net_sales'),
            'last_week' => $rows->sum('last_week'),
            'invoices' => $rows->sum('invoices'),
            'collected' => $rows->sum('collected'),
            'cash' => $rows->sum('cash'),
            ...($profit ? ['gross_profit' => $rows->sum('gross_profit'), 'last_week_profit' => $rows->sum('last_week_profit')] : []),
        ];

        return [
            'totals' => $totals,
            'locations' => $rows->values()->all(),
            'openOrders' => (clone $openOrders)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->get()
                ->map(fn ($r) => ['status' => $r->status->value, 'label' => $r->status->label(), 'count' => (int) $r->c])->values()->all(),
            'deliveries' => [
                'count' => (clone $deliveries)->count(),
                'next' => (clone $deliveries)->with(['customer:id,name', 'location:id,code'])->orderByRaw('requested_delivery_date IS NULL')->orderBy('requested_delivery_date')->limit(5)->get()
                    ->map(fn (SalesOrder $o) => ['id' => $o->id, 'number' => $o->number, 'customer' => $o->customer?->name, 'location' => $o->location?->code, 'date' => $o->requested_delivery_date?->toDateString()])->all(),
            ],
            'lowStock' => $this->stock->lowStockRows($ids)->count(),
            'transfersInTransit' => Transfer::query()->where('status', TransferStatus::Dispatched->value)
                ->where(fn ($q) => $q->whereIn('from_location_id', $ids)->orWhereIn('to_location_id', $ids))->count(),
            'pendingCheques' => ['count' => (clone $pendingCheques)->count(), 'amount' => (int) (clone $pendingCheques)->sum('amount')],
            'followUps' => [
                'count' => (clone $followUps)->count(),
                'next' => (clone $followUps)->with('customer:id,name')->orderBy('follow_up_date')->limit(5)->get()
                    ->map(fn (Quotation $q) => ['id' => $q->id, 'number' => $q->number, 'customer' => $q->customer?->name, 'date' => $q->follow_up_date?->toDateString(), 'total' => $q->grand_total])->all(),
            ],
            'overdueJobs' => [
                'count' => (clone $overdueJobs)->count(),
                'next' => (clone $overdueJobs)->with(['salesOrder:id,number,customer_id', 'salesOrder.customer:id,name', 'location:id,code'])->orderBy('due_date')->limit(5)->get()
                    ->map(fn (ProductionJob $j) => ['id' => $j->id, 'number' => $j->number, 'title' => $j->title, 'customer' => $j->salesOrder?->customer?->name, 'location' => $j->location?->code, 'due_date' => $j->due_date?->toDateString()])->all(),
            ],
            'shiftsWaiting' => Shift::query()->whereIn('location_id', $ids)->where('status', ShiftStatus::Closed->value)->count(),
            'canSeeProfit' => $profit,
        ];
    }

    /** Cash taken today (cash payments less cash refunds) at a location. */
    private function cashToday(int $locationId, string $date): int
    {
        $cash = fn ($q) => $q->where('code', PaymentMethodCode::Cash->value);

        return (int) Payment::query()->where('location_id', $locationId)->whereDate('business_date', $date)
            ->where('status', '!=', PaymentRecordStatus::Voided->value)->whereHas('method', $cash)->sum('amount')
            - (int) Refund::query()->where('location_id', $locationId)->whereDate('business_date', $date)->whereHas('method', $cash)->sum('amount');
    }
}
