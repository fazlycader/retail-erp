<?php

namespace App\Services\Reports;

use App\Enums\ChequeStatus;
use App\Enums\MovementType;
use App\Enums\PaymentRecordStatus;
use App\Enums\TransferStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Models\Payment;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Transfer;
use App\Services\Reports\Concerns\BuildsReports;
use App\Support\BusinessDay;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Stock, purchasing and cheque reports. Stock on hand, low stock and dead stock are snapshots of
 * now (the date range is ignored); valuation is on hand × the variant's current average cost.
 */
class StockReports
{
    use BuildsReports;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(string $key, ReportRange $range, array $options = []): array
    {
        return match ($key) {
            'stock_valuation' => $this->valuation($range),
            'low_stock' => $this->lowStock($range),
            'dead_stock' => $this->deadStock($range, max(7, min(730, (int) ($options['days'] ?? 90)))),
            'stock_movements' => $this->movements($range),
            'transfers' => $this->transfers($range),
            'purchases' => $this->purchases($range),
            'cheques' => $this->cheques($range),
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function levels(ReportRange $range): Collection
    {
        $locations = Location::query()->pluck('code', 'id');

        return StockLevel::query()->whereIn('location_id', $range->locationIds ?: [0])->where('on_hand', '>', 0)
            ->with(['variant:id,product_id,sku,name,average_cost,reorder_level,reorder_qty,price', 'variant.product:id,name,category_id', 'variant.product.category:id,name'])
            ->get()
            ->map(fn (StockLevel $l) => [
                'location_id' => $l->location_id,
                'location' => $locations[$l->location_id] ?? '?',
                'product_variant_id' => $l->product_variant_id,
                'sku' => $l->variant?->sku,
                'name' => trim(($l->variant?->product?->name ?? '').($l->variant?->name ? ' — '.$l->variant->name : '')),
                'category' => $l->variant?->product?->category?->name ?? '—',
                'on_hand' => (int) $l->on_hand,
                'reserved' => (int) $l->reserved,
                'available' => (int) ($l->on_hand - $l->reserved),
                'average_cost' => (int) $l->variant?->average_cost,
                'value' => Quantity::times((int) $l->on_hand, (int) $l->variant?->average_cost),
                'retail_value' => Quantity::times((int) $l->on_hand, (int) $l->variant?->price),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function valuation(ReportRange $range): array
    {
        $rows = $this->levels($range)->sortBy(['location', 'name'])->values();
        $byLocation = $rows->groupBy('location')->map(fn ($g, $loc) => ['location' => $loc, 'items' => $g->count(), 'on_hand' => $g->sum('on_hand'), 'value' => $g->sum('value'), 'retail_value' => $g->sum('retail_value')])->values();
        $byCategory = $rows->groupBy('category')->map(fn ($g, $cat) => ['category' => $cat, 'on_hand' => $g->sum('on_hand'), 'value' => $g->sum('value')])->sortByDesc('value')->values();

        return [
            'cards' => [
                $this->card('value', 'Stock value (cost)', $rows->sum('value'), 'money'),
                $this->card('retail', 'At selling price', $rows->sum('retail_value'), 'money'),
                $this->card('pieces', 'Pieces on hand', $rows->sum('on_hand'), 'quantity'),
                $this->card('items', 'Items in stock', $rows->pluck('product_variant_id')->unique()->count(), 'count'),
            ],
            'notes' => ['Stock on hand right now, valued at each item\'s average cost.'],
            'charts' => [['key' => 'locations', 'type' => 'bar', 'title' => 'Stock value by location', 'format' => 'money', 'labels' => $byLocation->pluck('location')->all(), 'datasets' => [['label' => 'Value', 'data' => $byLocation->pluck('value')->all()]]]],
            'tables' => [
                $this->table('locations', 'By location', [['location', 'Location', 'text'], ['items', 'Items', 'count'], ['on_hand', 'On hand', 'quantity'], ['value', 'Value (cost)', 'money'], ['retail_value', 'At selling price', 'money']], $byLocation->all(), totals: true),
                $this->table('categories', 'By category', [['category', 'Category', 'text'], ['on_hand', 'On hand', 'quantity'], ['value', 'Value (cost)', 'money']], $byCategory->all(), totals: true),
                $this->table('items', 'By item and location', [
                    ['location', 'Loc.', 'text'], ['sku', 'SKU', 'text'], ['name', 'Item', 'text'], ['category', 'Category', 'text'],
                    ['on_hand', 'On hand', 'quantity'], ['reserved', 'Reserved', 'quantity'], ['average_cost', 'Avg cost', 'money'], ['value', 'Value', 'money'],
                ], $rows->all(), totals: true),
            ],
        ];
    }

    /**
     * Items at or below their reorder level at a location (including ones with none left).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lowStockRows(array $locationIds): Collection
    {
        $locations = Location::query()->pluck('code', 'id');

        return StockLevel::query()->whereIn('location_id', $locationIds ?: [0])
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->where('product_variants.is_active', true)
            ->whereNull('product_variants.deleted_at')
            ->where('product_variants.reorder_level', '>', 0)
            ->whereRaw('stock_levels.on_hand - stock_levels.reserved <= product_variants.reorder_level')
            ->with(['variant.product:id,name'])
            ->get(['stock_levels.*'])
            ->map(fn (StockLevel $l) => [
                'location' => $locations[$l->location_id] ?? '?',
                'sku' => $l->variant?->sku,
                'name' => trim(($l->variant?->product?->name ?? '').($l->variant?->name ? ' — '.$l->variant->name : '')),
                'on_hand' => (int) $l->on_hand,
                'reserved' => (int) $l->reserved,
                'available' => (int) ($l->on_hand - $l->reserved),
                'reorder_level' => (int) $l->variant?->reorder_level,
                'reorder_qty' => (int) $l->variant?->reorder_qty,
            ])->sortBy('available')->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function lowStock(ReportRange $range): array
    {
        $rows = $this->lowStockRows($range->locationIds);

        return [
            'cards' => [
                $this->card('items', 'Low items', $rows->count(), 'count'),
                $this->card('out', 'Out of stock', $rows->where('available', '<=', 0)->count(), 'count'),
            ],
            'notes' => ['Available (on hand less reserved) at or below the reorder level, right now.'],
            'charts' => [],
            'tables' => [$this->table('items', 'Low stock', [
                ['location', 'Loc.', 'text'], ['sku', 'SKU', 'text'], ['name', 'Item', 'text'], ['on_hand', 'On hand', 'quantity'], ['reserved', 'Reserved', 'quantity'],
                ['available', 'Available', 'quantity'], ['reorder_level', 'Reorder level', 'quantity'], ['reorder_qty', 'Reorder qty', 'quantity'],
            ], $rows->all())],
        ];
    }

    /**
     * Stock on hand with no sale at that location in the last $days days.
     *
     * @return array<string, mixed>
     */
    public function deadStock(ReportRange $range, int $days): array
    {
        $since = CarbonImmutable::parse(BusinessDay::current($range->locationIds[0] ?? 0))->subDays($days)->toDateString();

        $lastSold = StockMovement::query()->whereIn('location_id', $range->locationIds ?: [0])->where('type', MovementType::Sale->value)
            ->selectRaw('location_id, product_variant_id, MAX(business_date) AS last_sold')->groupBy('location_id', 'product_variant_id')->get()
            ->mapWithKeys(fn ($r) => [$r->location_id.'|'.$r->product_variant_id => substr((string) $r->last_sold, 0, 10)]);

        $today = CarbonImmutable::parse(BusinessDay::current($range->locationIds[0] ?? 0));
        $rows = $this->levels($range)->map(function ($r) use ($lastSold) {
            $r['last_sold'] = $lastSold[$r['location_id'].'|'.$r['product_variant_id']] ?? null;

            return $r;
        })->filter(fn ($r) => $r['last_sold'] === null || $r['last_sold'] < $since)
            ->map(fn ($r) => [...$r, 'days_since' => $r['last_sold'] ? (int) CarbonImmutable::parse($r['last_sold'])->diffInDays($today) : null])
            ->sortByDesc('value')->values();

        return [
            'cards' => [
                $this->card('items', "No sale in {$days} days", $rows->count(), 'count'),
                $this->card('value', 'Money tied up (cost)', $rows->sum('value'), 'money'),
                $this->card('never', 'Never sold here', $rows->whereNull('last_sold')->count(), 'count'),
            ],
            'notes' => ["Stock on hand now with no sale at that location since {$since}. Change N with ?days=."],
            'charts' => [],
            'tables' => [$this->table('items', 'Slow-moving & dead stock', [
                ['location', 'Loc.', 'text'], ['sku', 'SKU', 'text'], ['name', 'Item', 'text'], ['category', 'Category', 'text'], ['on_hand', 'On hand', 'quantity'],
                ['last_sold', 'Last sold', 'date'], ['days_since', 'Days', 'count'], ['value', 'Value (cost)', 'money'],
            ], $rows->all(), totals: true)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function movements(ReportRange $range): array
    {
        $moves = $range->scope(StockMovement::query())->with(['variant:id,product_id,sku,name', 'variant.product:id,name'])->get(['id', 'product_variant_id', 'location_id', 'type', 'quantity', 'unit_cost']);
        $locations = Location::query()->pluck('code', 'id');
        $value = fn ($m) => Quantity::times((int) $m->quantity, (int) $m->unit_cost);

        $byType = $moves->groupBy(fn ($m) => $m->type->value)->map(fn ($g, $type) => [
            'type' => MovementType::from($type)->label(), 'count' => $g->count(),
            'in' => (int) $g->where('quantity', '>', 0)->sum('quantity'), 'out' => (int) -$g->where('quantity', '<', 0)->sum('quantity'), 'value' => (int) $g->sum($value),
        ])->values();
        $byLocation = $moves->groupBy('location_id')->map(fn ($g, $id) => [
            'location' => $locations[$id] ?? '?', 'in' => (int) $g->where('quantity', '>', 0)->sum('quantity'), 'out' => (int) -$g->where('quantity', '<', 0)->sum('quantity'), 'net' => (int) $g->sum('quantity'),
        ])->values();
        $byProduct = $moves->groupBy('product_variant_id')->map(fn ($g) => [
            'sku' => $g->first()->variant?->sku, 'name' => trim(($g->first()->variant?->product?->name ?? '').($g->first()->variant?->name ? ' — '.$g->first()->variant->name : '')),
            'in' => (int) $g->where('quantity', '>', 0)->sum('quantity'), 'out' => (int) -$g->where('quantity', '<', 0)->sum('quantity'), 'net' => (int) $g->sum('quantity'),
        ])->sortByDesc(fn ($r) => $r['in'] + $r['out'])->values();

        return [
            'cards' => [
                $this->card('moves', 'Movements', $moves->count(), 'count'),
                $this->card('in', 'Pieces in', $byType->sum('in'), 'quantity'),
                $this->card('out', 'Pieces out', $byType->sum('out'), 'quantity'),
            ],
            'charts' => [],
            'tables' => [
                $this->table('types', 'By movement type', [['type', 'Type', 'text'], ['count', 'Movements', 'count'], ['in', 'In', 'quantity'], ['out', 'Out', 'quantity'], ['value', 'Value (at cost)', 'money']], $byType->all(), totals: true),
                $this->table('locations', 'By location', [['location', 'Location', 'text'], ['in', 'In', 'quantity'], ['out', 'Out', 'quantity'], ['net', 'Net change', 'quantity']], $byLocation->all(), totals: true),
                $this->table('products', 'By product', [['sku', 'SKU', 'text'], ['name', 'Item', 'text'], ['in', 'In', 'quantity'], ['out', 'Out', 'quantity'], ['net', 'Net change', 'quantity']], $byProduct->all(), totals: true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transfers(ReportRange $range): array
    {
        [$start, $end] = $range->window();
        $ids = $range->locationIds ?: [0];
        $transfers = Transfer::query()
            ->where(fn ($q) => $q->whereIn('from_location_id', $ids)->orWhereIn('to_location_id', $ids))
            ->where(fn ($q) => $q->whereBetween('created_at', [$start, $end])->orWhereBetween('dispatched_at', [$start, $end])->orWhereBetween('received_at', [$start, $end]))
            ->with(['fromLocation:id,code', 'toLocation:id,code'])->withSum('lines', 'sent_qty')->withSum('lines', 'received_qty')->withCount('lines')
            ->orderBy('id')->get();

        $rows = $transfers->map(fn (Transfer $t) => [
            'number' => $t->number,
            'route' => ($t->fromLocation?->code ?? '?').' → '.($t->toLocation?->code ?? '?'),
            'status' => $t->status->label(),
            'dispatched' => $t->dispatched_at?->timezone(config('app.timezone'))->toDateString(),
            'received' => $t->received_at?->timezone(config('app.timezone'))->toDateString(),
            'lines' => $t->lines_count,
            'sent' => (int) $t->lines_sum_sent_qty,
            'received_qty' => (int) $t->lines_sum_received_qty,
        ]);
        $routes = $rows->groupBy('route')->map(fn ($g, $route) => ['route' => $route, 'transfers' => $g->count(), 'sent' => $g->sum('sent'), 'received_qty' => $g->sum('received_qty')])->values();

        return [
            'cards' => [
                $this->card('transfers', 'Transfers', $rows->count(), 'count'),
                $this->card('transit', 'On the way now', $transfers->where('status', TransferStatus::Dispatched)->count(), 'count'),
                $this->card('sent', 'Pieces sent', $rows->sum('sent'), 'quantity'),
                $this->card('short', 'Short on arrival', $transfers->where('status', TransferStatus::PartiallyReceived)->count(), 'count'),
            ],
            'charts' => [],
            'tables' => [
                $this->table('transfers', 'Transfers', [['number', 'Transfer', 'text'], ['route', 'Route', 'text'], ['status', 'Status', 'text'], ['dispatched', 'Dispatched', 'date'], ['received', 'Received', 'date'], ['lines', 'Lines', 'count'], ['sent', 'Sent', 'quantity'], ['received_qty', 'Received', 'quantity']], $rows->all(), totals: true),
                $this->table('routes', 'By route', [['route', 'Route', 'text'], ['transfers', 'Transfers', 'count'], ['sent', 'Sent', 'quantity'], ['received_qty', 'Received', 'quantity']], $routes->all(), totals: true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchases(ReportRange $range): array
    {
        $grns = $range->scope(GoodsReceipt::query())->with('supplier:id,name')->get(['id', 'supplier_id', 'location_id', 'subtotal', 'tax_total', 'total']);
        $suppliers = $grns->groupBy('supplier_id')->map(fn ($g) => [
            'supplier' => $g->first()->supplier?->name ?? '?', 'grns' => $g->count(), 'subtotal' => (int) $g->sum('subtotal'), 'tax' => (int) $g->sum('tax_total'), 'total' => (int) $g->sum('total'),
        ])->sortByDesc('total')->values();

        $lines = GoodsReceiptLine::query()->whereIn('goods_receipt_id', $grns->pluck('id'))->with(['variant:id,product_id,sku,name', 'variant.product:id,name', 'goodsReceipt:id,supplier_id', 'goodsReceipt.supplier:id,name'])->get();
        $products = $lines->groupBy('product_variant_id')->map(function ($g) {
            $qty = (int) $g->sum('received_qty');
            $cost = (int) $g->sum(fn ($l) => Quantity::times((int) $l->received_qty, (int) $l->unit_cost));

            return [
                'sku' => $g->first()->variant?->sku,
                'name' => trim(($g->first()->variant?->product?->name ?? '').($g->first()->variant?->name ? ' — '.$g->first()->variant->name : '')),
                'suppliers' => $g->map(fn ($l) => $l->goodsReceipt?->supplier?->name)->filter()->unique()->implode(', '),
                'quantity' => $qty,
                'cost' => $cost,
                'average' => $qty > 0 ? intdiv($cost * 1000 * 2 + $qty, $qty * 2) : 0,
            ];
        })->sortByDesc('cost')->values();

        return [
            'cards' => [
                $this->card('total', 'Purchased (incl. VAT)', (int) $grns->sum('total'), 'money'),
                $this->card('grns', 'Goods received notes', $grns->count(), 'count'),
                $this->card('suppliers', 'Suppliers', $suppliers->count(), 'count'),
            ],
            'charts' => [['key' => 'suppliers', 'type' => 'bar', 'horizontal' => true, 'title' => 'Top suppliers', 'format' => 'money', 'labels' => $suppliers->take(10)->pluck('supplier')->all(), 'datasets' => [['label' => 'Total', 'data' => $suppliers->take(10)->pluck('total')->all()]]]],
            'tables' => [
                $this->table('suppliers', 'By supplier', [['supplier', 'Supplier', 'text'], ['grns', 'GRNs', 'count'], ['subtotal', 'Before VAT', 'money'], ['tax', 'VAT', 'money'], ['total', 'Total', 'money']], $suppliers->all(), totals: true),
                $this->table('products', 'By product', [['sku', 'SKU', 'text'], ['name', 'Item', 'text'], ['suppliers', 'Supplier(s)', 'text'], ['quantity', 'Received', 'quantity'], ['cost', 'Cost', 'money'], ['average', 'Avg unit cost', 'money']], $products->all(), totals: true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cheques(ReportRange $range): array
    {
        $today = CarbonImmutable::parse(BusinessDay::current($range->locationIds[0] ?? 0));
        $locations = Location::query()->pluck('code', 'id');
        $row = fn (Payment $p) => [
            'number' => $p->number,
            'location' => $locations[$p->location_id] ?? '?',
            'customer' => $p->customer?->name ?? 'Walk-in',
            'cheque' => $p->cheque_number,
            'bank' => $p->cheque_bank,
            'cheque_date' => $p->cheque_date?->toDateString(),
            'status' => $p->cheque_status?->label(),
            'days_late' => $p->cheque_date && $p->cheque_date->lt($today) ? (int) $p->cheque_date->diffInDays($today) : 0,
            'amount' => $p->amount,
        ];

        $pending = Payment::query()->whereIn('location_id', $range->locationIds ?: [0])->where('status', PaymentRecordStatus::Completed->value)
            ->whereIn('cheque_status', [ChequeStatus::Pending->value, ChequeStatus::Deposited->value])
            ->with('customer:id,name')->orderBy('cheque_date')->get()->map($row);

        [$start, $end] = $range->window();
        $bounced = Payment::query()->whereIn('location_id', $range->locationIds ?: [0])->where('cheque_status', ChequeStatus::Bounced->value)
            ->whereBetween('cheque_status_at', [$start, $end])->with('customer:id,name')->orderBy('cheque_status_at')->get()->map($row);

        return [
            'cards' => [
                $this->card('pending', 'Not cleared yet', (int) $pending->sum('amount'), 'money'),
                $this->card('due', 'Due and not deposited', (int) $pending->where('status', ChequeStatus::Pending->label())->where('days_late', '>', 0)->sum('amount'), 'money'),
                $this->card('bounced', 'Bounced in period', (int) $bounced->sum('amount'), 'money'),
            ],
            'notes' => ['Pending and deposited cheques are as of now; bounced ones are those marked bounced in the period.'],
            'charts' => [],
            'tables' => [
                $this->table('pending', 'Not cleared (pending or deposited)', $cols = [['number', 'Receipt', 'text'], ['location', 'Loc.', 'text'], ['customer', 'Customer', 'text'], ['cheque', 'Cheque', 'text'], ['bank', 'Bank', 'text'], ['cheque_date', 'Dated', 'date'], ['status', 'Status', 'text'], ['days_late', 'Days past date', 'count'], ['amount', 'Amount', 'money']], $pending->all(), totals: true),
                $this->table('bounced', 'Bounced in the period', $cols, $bounced->all(), totals: true),
            ],
        ];
    }
}
