<?php

namespace App\Services\Reports;

use App\Enums\QuotationStatus;
use App\Enums\SalesChannel;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\User;
use App\Services\Reports\Concerns\BuildsReports;
use Illuminate\Support\Collection;

/**
 * Builds every report as one structure the screen, exports and print view all use:
 *
 *   cards:  [{key, label, value, format: money|count|percent|quantity|text, previous?, change?}]
 *   charts: [{key, type: bar|line|doughnut, title, labels, datasets: [{label, data}], format}]
 *   tables: [{key, title, columns: [{key, label, format}], rows, totals?}]
 *
 * Money is integer cents; quantities are milli-units (format "quantity"); percentages 0–100.
 * Profit figures (gross profit, margin, cost) are only added when $user can('view-profit').
 */
class ReportBuilder
{
    use BuildsReports;

    /** @var array<int, string>|null */
    private ?array $locationNames = null;

    public function __construct(private SalesReader $sales, private StockReports $stock) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(string $key, ReportRange $range, User $user, array $options = []): array
    {
        $profit = $user->can('view-profit');

        return match ($key) {
            'sales' => $this->salesSummary($range, $profit),
            'products' => $this->products($range),
            'profit' => $this->profit($range),
            'payments' => $this->payments($range),
            'discounts' => $this->discounts($range),
            'returns' => $this->returns($range),
            'quotations' => $this->quotations($range),
            default => $this->stock->build($key, $range, $options),
        };
    }

    // ---- Sales summary -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function salesSummary(ReportRange $range, bool $profit): array
    {
        $now = $this->derived($this->sales->totals($range));
        $before = $this->derived($this->sales->totals($range->previous()));

        $cardKeys = [
            'sales' => ['Sales (invoices)', 'money'], 'invoices_count' => ['Invoices', 'count'], 'average_invoice' => ['Average invoice', 'money'],
            'returns_total' => ['Returns', 'money'], 'net_sales' => ['Net sales', 'money'], 'discounts' => ['Discounts', 'money'],
            'collected' => ['Collected', 'money'], 'refunds' => ['Refunds', 'money'],
        ];
        if ($profit) {
            $cardKeys += ['gross_profit' => ['Gross profit', 'money'], 'margin' => ['Margin %', 'percent']];
        }

        $cards = [];
        foreach ($cardKeys as $key => [$label, $format]) {
            $cards[] = $this->card($key, $label, $now[$key], $format, $before[$key]);
        }

        // Without view-profit the rows never carry profit figures (not just hidden columns).
        $strip = fn (array $row) => $profit ? $row : array_diff_key($row, array_flip(['gross_profit', 'margin', 'cost', 'returns_cost', 'revenue_ex_tax', 'returns_revenue_ex_tax']));
        $perDay = array_map(fn ($d) => $strip($this->derived($d)), $this->sales->perDay($range));
        $byLocation = collect($this->sales->byLocation($range))->map(fn ($t, $id) => ['location' => $this->locationName($id), ...$strip($this->derived($t))]);
        $profitCols = $profit ? [['gross_profit', 'Gross profit', 'money'], ['margin', 'Margin %', 'percent']] : [];

        $charts = $range->isSingleDay()
            ? [['key' => 'locations', 'type' => 'bar', 'title' => 'Sales by location', 'format' => 'money', 'labels' => $byLocation->pluck('location')->all(), 'datasets' => [['label' => 'Sales', 'data' => $byLocation->pluck('sales')->all()]]]]
            : [[
                'key' => 'daily', 'type' => 'line', 'title' => 'Sales per day', 'format' => 'money', 'labels' => array_keys($perDay),
                'datasets' => [['label' => 'Sales', 'data' => array_column($perDay, 'sales')], ['label' => 'Collected', 'data' => array_column($perDay, 'collected')]],
            ]];

        $channels = collect($this->sales->byChannel($range))->map(fn ($v, $channel) => ['channel' => SalesChannel::tryFrom($channel)?->label() ?? $channel, ...$v])->values()->all();
        $people = collect($this->sales->bySalesperson($range))->map(fn ($r) => [
            'name' => $r['name'], 'invoices' => $r['invoices'], 'sales' => $r['sales'],
            ...($profit ? ['gross_profit' => $r['revenue_ex_tax'] - $r['cost'], 'margin' => $this->margin($r['revenue_ex_tax'], $r['cost'])] : []),
        ])->all();

        $dayRows = collect($perDay)->map(fn ($d, $date) => ['date' => $date, ...$d])->values()->all();

        return [
            'cards' => $cards,
            'comparison' => $range->previous()->toArray(),
            'charts' => $charts,
            'tables' => [
                $this->table('days', 'By day', [
                    ['date', 'Date', 'date'], ['invoices_count', 'Invoices', 'count'], ['items_gross', 'Gross', 'money'], ['discounts', 'Discounts', 'money'],
                    ['sales', 'Sales', 'money'], ['tax', 'VAT', 'money'], ['returns_total', 'Returns', 'money'], ['net_sales', 'Net sales', 'money'],
                    ['collected', 'Collected', 'money'], ['refunds', 'Refunds', 'money'], ...$profitCols,
                ], $dayRows, totals: true),
                $this->table('locations', 'By location', [
                    ['location', 'Location', 'text'], ['invoices_count', 'Invoices', 'count'], ['sales', 'Sales', 'money'], ['returns_total', 'Returns', 'money'],
                    ['net_sales', 'Net sales', 'money'], ['collected', 'Collected', 'money'], ...$profitCols,
                ], $byLocation->values()->all(), totals: true),
                $this->table('channels', 'By channel', [['channel', 'Channel', 'text'], ['invoices', 'Invoices', 'count'], ['sales', 'Sales', 'money']], $channels, totals: true),
                $this->table('salespeople', 'By salesperson', [['name', 'Salesperson', 'text'], ['invoices', 'Invoices', 'count'], ['sales', 'Sales', 'money'], ...$profitCols], $people, totals: true),
            ],
        ];
    }

    // ---- Products & categories ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function products(ReportRange $range): array
    {
        $byProduct = $this->byProduct($this->sales->variants($range));
        $byCategory = $byProduct->groupBy('category')->map(fn (Collection $g, $name) => [
            'category' => $name ?: '—', 'quantity' => $g->sum('quantity'), 'revenue' => $g->sum('revenue'), 'returned_qty' => $g->sum('returned_qty'), 'net_revenue' => $g->sum('net_revenue'),
        ])->sortByDesc('net_revenue')->values();
        $top = $byProduct->sortByDesc('net_revenue')->take(10);

        return [
            'cards' => [
                $this->card('quantity', 'Pieces sold', $byProduct->sum('quantity'), 'quantity'),
                $this->card('revenue', 'Item revenue', $byProduct->sum('revenue'), 'money'),
                $this->card('returned', 'Returned revenue', $byProduct->sum('returned_revenue'), 'money'),
                $this->card('top', 'Top seller', $top->first()['name'] ?? '—', 'text'),
            ],
            'charts' => [['key' => 'top', 'type' => 'bar', 'horizontal' => true, 'title' => 'Top 10 by net revenue', 'format' => 'money', 'labels' => $top->pluck('name')->all(), 'datasets' => [['label' => 'Net revenue', 'data' => $top->pluck('net_revenue')->all()]]]],
            'tables' => [
                $this->table('products', 'By product', [
                    ['sku', 'SKU', 'text'], ['name', 'Product', 'text'], ['category', 'Category', 'text'], ['quantity', 'Sold', 'quantity'], ['revenue', 'Revenue', 'money'],
                    ['discounts', 'Discounts', 'money'], ['returned_qty', 'Returned', 'quantity'], ['returned_revenue', 'Returned value', 'money'], ['net_revenue', 'Net revenue', 'money'],
                ], $byProduct->sortByDesc('net_revenue')->values()->all(), totals: true),
                $this->table('categories', 'By category', [['category', 'Category', 'text'], ['quantity', 'Sold', 'quantity'], ['revenue', 'Revenue', 'money'], ['returned_qty', 'Returned', 'quantity'], ['net_revenue', 'Net revenue', 'money']], $byCategory->all(), totals: true),
            ],
        ];
    }

    // ---- Gross profit ------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function profit(ReportRange $range): array
    {
        $variants = $this->sales->variants($range);
        $byProduct = $this->byProduct($variants);
        $rows = fn (Collection $g) => [
            'revenue_ex_tax' => $g->sum('net_revenue_ex_tax'), 'cost' => $g->sum('net_cost'),
            'gross_profit' => $g->sum('net_revenue_ex_tax') - $g->sum('net_cost'), 'margin' => $this->margin($g->sum('net_revenue_ex_tax'), $g->sum('net_cost')),
        ];
        $byCategory = $byProduct->groupBy('category')->map(fn ($g, $name) => ['category' => $name ?: '—', ...$rows($g)])->sortByDesc('gross_profit')->values();
        $byLocation = $variants->groupBy('location_id')->map(fn ($g, $id) => ['location' => $this->locationName((int) $id), ...$rows($this->withNet($g))])->sortByDesc('gross_profit')->values();
        $total = $rows($byProduct);
        $cols = [['revenue_ex_tax', 'Revenue (ex VAT)', 'money'], ['cost', 'Cost', 'money'], ['gross_profit', 'Gross profit', 'money'], ['margin', 'Margin %', 'percent']];

        return [
            'cards' => [
                $this->card('revenue', 'Revenue (ex VAT)', $total['revenue_ex_tax'], 'money'),
                $this->card('cost', 'Cost of sales', $total['cost'], 'money'),
                $this->card('gross_profit', 'Gross profit', $total['gross_profit'], 'money'),
                $this->card('margin', 'Margin', $total['margin'], 'percent'),
            ],
            'notes' => ['Net of returns. Cost is the average cost when each item was sold.'],
            'charts' => [['key' => 'categories', 'type' => 'bar', 'title' => 'Gross profit by category', 'format' => 'money', 'labels' => $byCategory->pluck('category')->all(), 'datasets' => [['label' => 'Gross profit', 'data' => $byCategory->pluck('gross_profit')->all()]]]],
            'tables' => [
                $this->table('products', 'By product', [['sku', 'SKU', 'text'], ['name', 'Product', 'text'], ['category', 'Category', 'text'], ['net_quantity', 'Qty', 'quantity'], ...$cols],
                    $byProduct->map(fn ($p) => [...$p, ...$rows(collect([$p]))])->sortByDesc('gross_profit')->values()->all(), totals: true),
                $this->table('categories', 'By category', [['category', 'Category', 'text'], ...$cols], $byCategory->all(), totals: true),
                $this->table('locations', 'By location', [['location', 'Location', 'text'], ...$cols], $byLocation->all(), totals: true),
            ],
        ];
    }

    // ---- Payments ----------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function payments(ReportRange $range): array
    {
        $rows = collect($this->sales->byMethod($range));
        $total = max(1, $rows->sum('amount'));
        $rows = $rows->map(fn ($r) => [...$r, 'net' => $r['amount'] - $r['refunds'], 'share' => round($r['amount'] * 100 / $total, 1)]);

        return [
            'cards' => [
                $this->card('amount', 'Payments taken', $rows->sum('amount'), 'money'),
                $this->card('count', 'Payments', $rows->sum('count'), 'count'),
                $this->card('refunds', 'Refunds', $rows->sum('refunds'), 'money'),
                $this->card('net', 'Net', $rows->sum('net'), 'money'),
            ],
            'charts' => [['key' => 'methods', 'type' => 'doughnut', 'title' => 'Share by method', 'format' => 'money', 'labels' => $rows->pluck('name')->all(), 'datasets' => [['label' => 'Amount', 'data' => $rows->pluck('amount')->all()]]]],
            'tables' => [$this->table('methods', 'Payment methods', [
                ['name', 'Method', 'text'], ['count', 'Payments', 'count'], ['amount', 'Amount', 'money'], ['refunds', 'Refunds', 'money'], ['net', 'Net', 'money'], ['share', 'Share %', 'percent'],
            ], $rows->values()->all(), totals: true)],
        ];
    }

    // ---- Discounts & approvals ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function discounts(ReportRange $range): array
    {
        $orders = app(SalesData::class)->invoices($range)
            ->where(fn ($q) => $q->where('line_discount_total', '>', 0)->orWhere('order_discount_cents', '>', 0))
            ->with(['location:id,code', 'salesperson:id,name', 'customer:id,name', 'discountApprover:id,name'])
            ->orderBy('business_date')->orderBy('id')->get()
            ->map(fn (SalesOrder $o) => [
                'date' => $o->business_date?->toDateString(),
                'invoice' => $o->invoice_number,
                'location' => $o->location?->code,
                'salesperson' => $o->salesperson?->name,
                'customer' => $o->customer?->name ?? 'Walk-in',
                'gross' => $o->items_gross_total,
                'line_discounts' => $o->line_discount_total,
                'order_discount' => $o->order_discount_cents,
                'discount' => $o->line_discount_total + $o->order_discount_cents,
                'percent' => $o->items_gross_total ? round(($o->line_discount_total + $o->order_discount_cents) * 100 / $o->items_gross_total, 1) : 0,
                'approved_by' => $o->discountApprover?->name,
            ]);

        [$start, $end] = $range->window();
        $approvals = AuditLog::query()
            ->whereIn('action', ['sales.discount_override', 'price.below_minimum'])
            ->whereIn('location_id', $range->locationIds ?: [0])
            ->whereBetween('created_at', [$start, $end])
            ->with('user:id,name')->orderBy('id')->get()
            ->map(fn (AuditLog $a) => [
                'at' => $a->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'type' => $a->action === 'price.below_minimum' ? 'Below minimum price' : 'Discount over limit',
                'summary' => $a->summary,
                'by' => $a->user?->name,
                'approved_by' => User::query()->whereKey($a->after['approved_by'] ?? 0)->value('name'),
            ]);

        $byPerson = $orders->groupBy(fn ($o) => $o['salesperson'] ?? '—')->map(fn ($g, $name) => ['name' => $name, 'invoices' => $g->count(), 'discount' => $g->sum('discount')])->sortByDesc('discount')->values();

        return [
            'cards' => [
                $this->card('discount', 'Discounts given', $orders->sum('discount'), 'money'),
                $this->card('invoices', 'Invoices with a discount', $orders->count(), 'count'),
                $this->card('approvals', 'Manager approvals', $approvals->count(), 'count'),
                $this->card('average', 'Average discount', $orders->count() ? round($orders->avg('percent'), 1) : 0, 'percent'),
            ],
            'charts' => [],
            'tables' => [
                $this->table('invoices', 'Invoices with discounts', [
                    ['date', 'Date', 'date'], ['invoice', 'Invoice', 'text'], ['location', 'Loc.', 'text'], ['salesperson', 'Salesperson', 'text'], ['customer', 'Customer', 'text'],
                    ['gross', 'Gross', 'money'], ['line_discounts', 'Line disc.', 'money'], ['order_discount', 'Order disc.', 'money'], ['percent', 'Disc. %', 'percent'], ['approved_by', 'Approved by', 'text'],
                ], $orders->all(), totals: true),
                $this->table('approvals', 'Manager approvals', [['at', 'When', 'text'], ['type', 'Type', 'text'], ['summary', 'What', 'text'], ['by', 'Asked by', 'text'], ['approved_by', 'Approved by', 'text']], $approvals->all()),
                $this->table('people', 'By salesperson', [['name', 'Salesperson', 'text'], ['invoices', 'Invoices', 'count'], ['discount', 'Discounts', 'money']], $byPerson->all(), totals: true),
            ],
        ];
    }

    // ---- Returns -----------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function returns(ReportRange $range): array
    {
        $returns = $range->scope(SalesReturn::query())
            ->with(['location:id,code', 'salesOrder:id,invoice_number,number', 'customer:id,name', 'createdBy:id,name', 'approvedBy:id,name'])
            ->orderBy('business_date')->orderBy('id')->get();

        $rows = $returns->map(fn (SalesReturn $r) => [
            'date' => $r->business_date->toDateString(),
            'number' => $r->number,
            'location' => $r->location?->code,
            'invoice' => $r->salesOrder?->invoice_number ?? $r->salesOrder?->number,
            'customer' => $r->customer?->name ?? 'Walk-in',
            'outcome' => $r->outcome->label(),
            'reason' => $r->reason,
            'total' => $r->total,
            'settled' => $r->settled_amount,
            'by' => $r->createdBy?->name,
            'approved_by' => $r->approvedBy?->name,
        ]);

        $lines = SalesReturnLine::query()->whereIn('sales_return_id', $returns->pluck('id'))->get();
        $conditions = $lines->groupBy(fn ($l) => $l->condition->label())->map(fn ($g, $c) => ['condition' => $c, 'quantity' => $g->sum('quantity'), 'amount' => $g->sum('amount')])->values();
        $outcomes = $rows->groupBy('outcome')->map(fn ($g, $o) => ['outcome' => $o, 'count' => $g->count(), 'total' => $g->sum('total'), 'settled' => $g->sum('settled')])->values();
        $reasons = $rows->groupBy(fn ($r) => mb_strtolower(trim($r['reason'])))->map(fn ($g) => ['reason' => $g->first()['reason'], 'count' => $g->count(), 'total' => $g->sum('total')])->sortByDesc('count')->values();

        return [
            'cards' => [
                $this->card('count', 'Returns', $rows->count(), 'count'),
                $this->card('total', 'Value returned', $rows->sum('total'), 'money'),
                $this->card('settled', 'Refunded / credited', $rows->sum('settled'), 'money'),
                $this->card('damaged', 'Damaged pieces', (int) $lines->where('condition.value', 'damaged')->sum('quantity'), 'quantity'),
            ],
            'charts' => [['key' => 'outcomes', 'type' => 'doughnut', 'title' => 'By outcome', 'format' => 'money', 'labels' => $outcomes->pluck('outcome')->all(), 'datasets' => [['label' => 'Value', 'data' => $outcomes->pluck('total')->all()]]]],
            'tables' => [
                $this->table('returns', 'Returns', [
                    ['date', 'Date', 'date'], ['number', 'Return', 'text'], ['location', 'Loc.', 'text'], ['invoice', 'Invoice', 'text'], ['customer', 'Customer', 'text'],
                    ['outcome', 'Outcome', 'text'], ['reason', 'Reason', 'text'], ['total', 'Value', 'money'], ['settled', 'Settled', 'money'], ['by', 'By', 'text'], ['approved_by', 'Approved by', 'text'],
                ], $rows->all(), totals: true),
                $this->table('reasons', 'By reason', [['reason', 'Reason', 'text'], ['count', 'Returns', 'count'], ['total', 'Value', 'money']], $reasons->all(), totals: true),
                $this->table('conditions', 'By condition', [['condition', 'Condition', 'text'], ['quantity', 'Pieces', 'quantity'], ['amount', 'Value', 'money']], $conditions->all(), totals: true),
                $this->table('outcomes', 'By outcome', [['outcome', 'Outcome', 'text'], ['count', 'Returns', 'count'], ['total', 'Value', 'money'], ['settled', 'Settled', 'money']], $outcomes->all(), totals: true),
            ],
        ];
    }

    // ---- Quotation conversion ----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function quotations(ReportRange $range): array
    {
        [$start, $end] = $range->window();
        $quotes = Quotation::query()->whereIn('location_id', $range->locationIds ?: [0])->whereBetween('created_at', [$start, $end])
            ->with('salesperson:id,name')->get(['id', 'status', 'grand_total', 'salesperson_id', 'location_id']);

        $converted = fn (Collection $g) => $g->where('status', QuotationStatus::Converted)->count();
        $decided = fn (Collection $g) => $g->whereNotIn('status', [QuotationStatus::Draft])->count();
        $rate = fn (Collection $g) => $decided($g) ? round($converted($g) * 100 / $decided($g), 1) : 0;

        $byStatus = collect(QuotationStatus::cases())->map(fn (QuotationStatus $s) => [
            'status' => $s->label(), 'count' => $quotes->where('status', $s)->count(), 'value' => (int) $quotes->where('status', $s)->sum('grand_total'),
        ])->filter(fn ($r) => $r['count'] > 0)->values();

        $people = $quotes->groupBy(fn ($q) => $q->salesperson?->name ?? '—')->map(fn ($g, $name) => [
            'name' => $name, 'quotes' => $g->count(), 'value' => (int) $g->sum('grand_total'),
            'converted' => $converted($g), 'converted_value' => (int) $g->where('status', QuotationStatus::Converted)->sum('grand_total'), 'rate' => $rate($g),
        ])->sortByDesc('value')->values();

        return [
            'cards' => [
                $this->card('quotes', 'Quotations', $quotes->count(), 'count'),
                $this->card('value', 'Value quoted', (int) $quotes->sum('grand_total'), 'money'),
                $this->card('converted', 'Converted to orders', $converted($quotes), 'count'),
                $this->card('rate', 'Conversion rate', $rate($quotes), 'percent'),
            ],
            'notes' => ['Quotations created in the period. Conversion rate = converted ÷ quotations that left draft.'],
            'charts' => [['key' => 'status', 'type' => 'doughnut', 'title' => 'By status', 'format' => 'count', 'labels' => $byStatus->pluck('status')->all(), 'datasets' => [['label' => 'Quotations', 'data' => $byStatus->pluck('count')->all()]]]],
            'tables' => [
                $this->table('status', 'By status', [['status', 'Status', 'text'], ['count', 'Quotations', 'count'], ['value', 'Value', 'money']], $byStatus->all(), totals: true),
                $this->table('salespeople', 'By salesperson', [['name', 'Salesperson', 'text'], ['quotes', 'Quotations', 'count'], ['value', 'Value', 'money'], ['converted', 'Converted', 'count'], ['converted_value', 'Converted value', 'money'], ['rate', 'Rate %', 'percent']], $people->all(), totals: true),
            ],
        ];
    }

    // ---- Helpers -----------------------------------------------------------------------------

    /**
     * Totals plus the figures worked out from them.
     *
     * @param  array<string, int>  $t
     * @return array<string, int|float>
     */
    public function derived(array $t): array
    {
        $revenue = $t['revenue_ex_tax'] - $t['returns_revenue_ex_tax'];
        $cost = $t['cost'] - $t['returns_cost'];

        return [
            ...$t,
            'net_sales' => $t['sales'] - $t['returns_total'],
            'average_invoice' => $t['invoices_count'] ? intdiv($t['sales'] * 2 + $t['invoices_count'], 2 * $t['invoices_count']) : 0,
            'gross_profit' => $revenue - $cost,
            'margin' => $this->margin($revenue, $cost),
        ];
    }

    /**
     * Variant rows (any locations) merged per product variant, with net-of-returns figures.
     *
     * @param  Collection<int, array<string, mixed>>  $variants
     * @return Collection<int, array<string, mixed>>
     */
    public function byProduct(Collection $variants): Collection
    {
        return $this->withNet($variants->groupBy(fn ($r) => $r['product_variant_id'] ?? 'custom:'.$r['name'])->map(fn (Collection $g) => [
            'product_variant_id' => $g->first()['product_variant_id'],
            'sku' => $g->first()['sku'],
            'name' => $g->first()['name'],
            'category' => $g->first()['category'],
            ...collect(['quantity', 'revenue', 'revenue_ex_tax', 'discounts', 'cost', 'returned_qty', 'returned_revenue', 'returned_revenue_ex_tax', 'returned_cost'])->mapWithKeys(fn ($k) => [$k => (int) $g->sum($k)])->all(),
        ])->values());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withNet(Collection $rows): Collection
    {
        return $rows->map(fn ($r) => [
            ...$r,
            'net_quantity' => $r['quantity'] - $r['returned_qty'],
            'net_revenue' => $r['revenue'] - $r['returned_revenue'],
            'net_revenue_ex_tax' => $r['revenue_ex_tax'] - $r['returned_revenue_ex_tax'],
            'net_cost' => $r['cost'] - $r['returned_cost'],
        ]);
    }

    private function margin(int $revenue, int $cost): float
    {
        return $revenue > 0 ? round(($revenue - $cost) * 100 / $revenue, 1) : 0.0;
    }

    private function locationName(int $id): string
    {
        $this->locationNames ??= Location::query()->pluck('name', 'id')->all();

        return $this->locationNames[$id] ?? (string) Location::query()->whereKey($id)->value('name');
    }
}
