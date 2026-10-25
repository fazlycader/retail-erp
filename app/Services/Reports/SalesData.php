<?php

namespace App\Services\Reports;

use App\Enums\PaymentRecordStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Live sales figures for some locations and business dates. The daily summary tables are built
 * from exactly these methods (one location-day at a time), so summaries and live reports agree.
 *
 * Definitions:
 * - Sales are invoices (confirmed sales orders, not cancelled) on their business date, at the
 *   selling location. sales = invoice grand totals; discounts = line + order discounts.
 * - Revenue ex VAT = item line totals after discounts less their VAT; cost = quantity × the
 *   average cost when the line was sold; gross profit = revenue ex VAT − cost.
 * - Returns count on the return's business date (value, revenue ex VAT and cost of the pieces).
 * - Collected = payments taken (not voided) on the date; refunds = refunds paid on the date.
 */
class SalesData
{
    public function invoices(ReportRange $range): Builder
    {
        return $range->scope(SalesOrder::query())
            ->whereNotNull('invoice_number')
            ->where('status', '!=', SalesOrderStatus::Cancelled->value);
    }

    /**
     * @return array<string, int>
     */
    public function totals(ReportRange $range): array
    {
        $inv = $this->invoices($range)->selectRaw('
            COUNT(*) AS invoices_count,
            COALESCE(SUM(items_gross_total), 0) AS items_gross,
            COALESCE(SUM(line_discount_total + order_discount_cents), 0) AS discounts,
            COALESCE(SUM(grand_total), 0) AS sales,
            COALESCE(SUM(tax_total), 0) AS tax,
            COALESCE(SUM(delivery_charge), 0) AS delivery
        ')->first();

        $lines = $this->variants($range);
        $returns = $range->scope(SalesReturn::query())->selectRaw('COUNT(*) AS c, COALESCE(SUM(total), 0) AS total')->first();
        $payments = $range->scope(Payment::query())->where('status', '!=', PaymentRecordStatus::Voided->value)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS amount')->first();

        return [
            'invoices_count' => (int) $inv->invoices_count,
            'items_gross' => (int) $inv->items_gross,
            'discounts' => (int) $inv->discounts,
            'sales' => (int) $inv->sales,
            'tax' => (int) $inv->tax,
            'delivery' => (int) $inv->delivery,
            'revenue_ex_tax' => (int) $lines->sum('revenue_ex_tax'),
            'cost' => (int) $lines->sum('cost'),
            'returns_count' => (int) $returns->c,
            'returns_total' => (int) $returns->total,
            'returns_revenue_ex_tax' => (int) $lines->sum('returned_revenue_ex_tax'),
            'returns_cost' => (int) $lines->sum('returned_cost'),
            'payments_count' => (int) $payments->c,
            'collected' => (int) $payments->amount,
            'refunds' => (int) $range->scope(Refund::query())->sum('amount'),
            'cancelled_count' => $range->scope(SalesOrder::query())->where('status', SalesOrderStatus::Cancelled->value)->whereNotNull('invoice_number')->count(),
        ];
    }

    /**
     * @return array<string, array<string, int>> totals per business date
     */
    public function perDay(ReportRange $range): array
    {
        $out = [];
        foreach ($range->dates() as $date) {
            $out[$date] = $this->totals($range->only([$date]));
        }

        return $out;
    }

    /**
     * @return array<int, array{id: int, name: string, count: int, amount: int, refunds: int}>
     */
    public function byMethod(ReportRange $range): array
    {
        $names = PaymentMethod::query()->pluck('name', 'id');
        $payments = $range->scope(Payment::query())->where('status', '!=', PaymentRecordStatus::Voided->value)
            ->selectRaw('payment_method_id, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS amount')->groupBy('payment_method_id')->get()->keyBy('payment_method_id');
        $refunds = $range->scope(Refund::query())->selectRaw('payment_method_id, COALESCE(SUM(amount), 0) AS amount')->groupBy('payment_method_id')->pluck('amount', 'payment_method_id');

        return collect($payments->keys())->merge($refunds->keys())->unique()->map(fn ($id) => [
            'id' => (int) $id,
            'name' => $names[$id] ?? '?',
            'count' => (int) ($payments[$id]->c ?? 0),
            'amount' => (int) ($payments[$id]->amount ?? 0),
            'refunds' => (int) ($refunds[$id] ?? 0),
        ])->sortBy('id')->values()->all();
    }

    /**
     * @return array<string, array{invoices: int, sales: int}>
     */
    public function byChannel(ReportRange $range): array
    {
        return $this->invoices($range)->selectRaw('channel, COUNT(*) AS c, COALESCE(SUM(grand_total), 0) AS sales')->groupBy('channel')->get()
            ->mapWithKeys(fn ($r) => [$r->channel->value => ['invoices' => (int) $r->c, 'sales' => (int) $r->sales]])->all();
    }

    /**
     * @return array<string, array{id: int|null, name: string, invoices: int, sales: int, revenue_ex_tax: int, cost: int}>
     */
    public function bySalesperson(ReportRange $range): array
    {
        $invoices = $this->invoices($range)->get(['id', 'salesperson_id', 'grand_total']);
        $names = User::query()->whereIn('id', $invoices->pluck('salesperson_id')->filter()->unique())->pluck('name', 'id');
        $lines = $this->lineFigures(SalesOrderLine::query()->whereIn('sales_order_id', $invoices->pluck('id'))->get(['sales_order_id', 'quantity', 'line_total', 'tax_cents', 'unit_cost_snapshot']))->groupBy('sales_order_id');

        return $invoices->groupBy(fn ($o) => (string) ($o->salesperson_id ?? 0))->map(function (Collection $orders, $id) use ($names, $lines) {
            $own = $orders->flatMap(fn ($o) => $lines->get($o->id, collect()));

            return [
                'id' => $id === '0' ? null : (int) $id,
                'name' => $names[(int) $id] ?? 'No salesperson',
                'invoices' => $orders->count(),
                'sales' => (int) $orders->sum('grand_total'),
                'revenue_ex_tax' => (int) $own->sum('revenue_ex_tax'),
                'cost' => (int) $own->sum('cost'),
            ];
        })->all();
    }

    /**
     * Sales and returns per location and product variant (custom items by name), for the range.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function variants(ReportRange $range): Collection
    {
        $sold = SalesOrderLine::query()
            ->whereIn('sales_order_id', $this->invoices($range)->select('id'))
            ->with(['salesOrder:id,location_id,business_date', 'variant:id,product_id,sku', 'variant.product:id,category_id', 'variant.product.category:id,name'])
            ->get();

        $returned = SalesReturnLine::query()
            ->whereIn('sales_return_id', $range->scope(SalesReturn::query())->select('id'))
            ->with(['salesReturn:id,location_id', 'orderLine', 'orderLine.variant:id,product_id,sku', 'orderLine.variant.product:id,category_id', 'orderLine.variant.product.category:id,name'])
            ->get();

        $rows = [];
        $row = function (int $locationId, ?SalesOrderLine $line) use (&$rows) {
            $variant = $line?->variant;
            $key = $locationId.'|'.($line?->product_variant_id ?? 'custom:'.$line?->name_snapshot);

            return $rows[$key] ??= [
                'location_id' => $locationId,
                'product_variant_id' => $line?->product_variant_id,
                'product_id' => $variant?->product_id,
                'category_id' => $variant?->product?->category_id,
                'name' => $line?->name_snapshot ?? '?',
                'sku' => $line?->sku_snapshot,
                'category' => $variant?->product?->category?->name ?? ($line?->is_service ? 'Services' : 'Custom'),
                'quantity' => 0, 'revenue' => 0, 'revenue_ex_tax' => 0, 'discounts' => 0, 'cost' => 0,
                'returned_qty' => 0, 'returned_revenue' => 0, 'returned_revenue_ex_tax' => 0, 'returned_cost' => 0,
                'last_sold_on' => null,
            ];
        };

        foreach ($sold as $line) {
            $key = $line->salesOrder->location_id.'|'.($line->product_variant_id ?? 'custom:'.$line->name_snapshot);
            $row($line->salesOrder->location_id, $line);
            $rows[$key]['quantity'] += $line->quantity;
            $rows[$key]['revenue'] += $line->line_total;
            $rows[$key]['revenue_ex_tax'] += $line->line_total - $line->tax_cents;
            $rows[$key]['discounts'] += $line->discount_cents + $line->order_discount_share;
            $rows[$key]['cost'] += Quantity::times($line->quantity, $line->unit_cost_snapshot);
            $date = $line->salesOrder->business_date?->toDateString();
            $rows[$key]['last_sold_on'] = max($rows[$key]['last_sold_on'] ?? '', (string) $date) ?: null;
        }

        foreach ($returned as $rl) {
            $line = $rl->orderLine;
            $key = $rl->salesReturn->location_id.'|'.($line?->product_variant_id ?? 'custom:'.$line?->name_snapshot);
            $row($rl->salesReturn->location_id, $line);
            $exTax = $line && $line->line_total > 0 ? intdiv($rl->amount * ($line->line_total - $line->tax_cents) * 2 + $line->line_total, $line->line_total * 2) : $rl->amount;
            $rows[$key]['returned_qty'] += $rl->quantity;
            $rows[$key]['returned_revenue'] += $rl->amount;
            $rows[$key]['returned_revenue_ex_tax'] += $exTax;
            $rows[$key]['returned_cost'] += Quantity::times($rl->quantity, (int) $line?->unit_cost_snapshot);
        }

        return collect(array_values($rows));
    }

    /**
     * @param  Collection<int, SalesOrderLine>  $lines
     * @return Collection<int, array{sales_order_id: int, revenue_ex_tax: int, cost: int}>
     */
    private function lineFigures(Collection $lines): Collection
    {
        return $lines->map(fn (SalesOrderLine $l) => [
            'sales_order_id' => $l->sales_order_id,
            'revenue_ex_tax' => $l->line_total - $l->tax_cents,
            'cost' => Quantity::times($l->quantity, $l->unit_cost_snapshot),
        ]);
    }
}
