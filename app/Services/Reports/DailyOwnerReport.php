<?php

namespace App\Services\Reports;

use App\Enums\ShiftStatus;
use App\Models\DayClose;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * The owner's daily email: one business date, every active location (vs the same day last week),
 * top items, low stock now and cash variances.
 */
class DailyOwnerReport
{
    public function __construct(private SalesReader $sales, private ReportBuilder $builder, private StockReports $stock) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $date): array
    {
        $locations = Location::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);
        $ids = $locations->pluck('id')->all();
        $range = new ReportRange($ids, $date, $date);
        $lastWeek = CarbonImmutable::parse($date)->subWeek()->toDateString();

        $rows = $locations->map(function (Location $l) use ($date, $lastWeek) {
            $now = $this->builder->derived($this->sales->totals(new ReportRange([$l->id], $date, $date)));
            $before = $this->builder->derived($this->sales->totals(new ReportRange([$l->id], $lastWeek, $lastWeek)));

            return [
                'name' => $l->name,
                'invoices' => $now['invoices_count'],
                'sales' => $now['sales'],
                'net_sales' => $now['net_sales'],
                'previous_net_sales' => $before['net_sales'],
                'collected' => $now['collected'] - $now['refunds'],
                'gross_profit' => $now['gross_profit'],
                'closed' => DayClose::isLocked($l->id, $date),
            ];
        });

        $top = $this->builder->byProduct($this->sales->variants($range))->sortByDesc('net_revenue')->take(10)
            ->map(fn ($p) => ['name' => $p['name'], 'quantity' => $p['net_quantity'], 'revenue' => $p['net_revenue']])->values()->all();

        $variances = Shift::query()->whereIn('location_id', $ids)->whereDate('business_date', $date)->whereNotNull('variance')->where('variance', '!=', 0)
            ->with(['user:id,name', 'location:id,code'])->get()
            ->map(fn (Shift $s) => ['location' => $s->location?->code, 'cashier' => $s->user?->name, 'variance' => $s->variance, 'waiting' => $s->status === ShiftStatus::Closed])->all();

        $low = $this->stock->lowStockRows($ids);

        return [
            'business_date' => $date,
            'previous_date' => $lastWeek,
            'locations' => $rows->all(),
            'totals' => [
                'sales' => $rows->sum('sales'),
                'net_sales' => $rows->sum('net_sales'),
                'previous_net_sales' => $rows->sum('previous_net_sales'),
                'collected' => $rows->sum('collected'),
                'gross_profit' => $rows->sum('gross_profit'),
                'invoices' => $rows->sum('invoices'),
            ],
            'top_items' => $top,
            'variances' => $variances,
            'low_stock' => ['count' => $low->count(), 'items' => $low->take(10)->all()],
            'unclosed' => $rows->where('closed', false)->pluck('name')->values()->all(),
        ];
    }

    /** Anything happened that day at any location (else no email). */
    public function hadActivity(string $date): bool
    {
        return SalesOrder::query()->whereDate('business_date', $date)->exists()
            || Payment::query()->whereDate('business_date', $date)->exists()
            || Shift::query()->whereDate('business_date', $date)->exists();
    }

    /** Every active location that traded that day has closed it. */
    public function allClosed(string $date): bool
    {
        foreach (Location::query()->where('is_active', true)->pluck('id') as $id) {
            $traded = SalesOrder::query()->where('location_id', $id)->whereDate('business_date', $date)->exists()
                || Payment::query()->where('location_id', $id)->whereDate('business_date', $date)->exists()
                || Shift::query()->where('location_id', $id)->whereDate('business_date', $date)->exists();

            if ($traded && ! DayClose::isLocked($id, $date)) {
                return false;
            }
        }

        return true;
    }
}
