@extends('documents.layout', ['number' => $order->invoice_number])

@section('meta')
    <div class="bold" style="font-size: 12px; margin-top: 4px">{{ $order->invoice_number ?? $order->number }}</div>
    <div>Date: {{ ($order->confirmed_at ?? $order->created_at)->timezone(config('app.timezone'))->format('d M Y, H:i') }}</div>
    <div>Order: {{ $order->number }}</div>
    @if ($order->salesperson)<div>Served by: {{ $order->salesperson->name }}</div>@endif
@endsection

@section('content')
<table style="margin-top: 14px">
    <tr>
        <td style="width: 50%; padding-right: 6px">@include('documents._customer', ['customer' => $order->customer, 'address' => $order->delivery_address])</td>
        <td style="width: 50%; padding-left: 6px">
            <div class="box">
                <div class="label">Sale</div>
                <div>{{ $order->kind->label() }} · {{ $order->fulfilment_type->label() }}</div>
                <div>Status: {{ $order->status->label() }} · {{ $order->payment_status->label() }}</div>
                @if ($order->requested_delivery_date)<div>Delivery date: {{ $order->requested_delivery_date->format('d M Y') }}</div>@endif
            </div>
        </td>
    </tr>
</table>

@if ($order->status === \App\Enums\SalesOrderStatus::Cancelled)
    <div style="margin-top: 10px"><span class="stamp">Cancelled</span></div>
@endif

<table class="items">
    <thead>
        <tr><th>Item</th><th class="right">Qty</th><th class="right">Unit price</th><th class="right">Discount</th><th class="right">Amount</th></tr>
    </thead>
    <tbody>
    @foreach ($order->lines as $line)
        <tr>
            <td>
                <div class="bold">{{ $line->name_snapshot }}</div>
                @if ($line->sku_snapshot)<div class="muted">{{ $line->sku_snapshot }}</div>@endif
                @if ($line->attributes_snapshot)<div class="muted">{{ collect($line->attributes_snapshot)->map(fn ($v, $k) => "$k: $v")->implode(' · ') }}</div>@endif
                @if ($line->custom_spec)<div class="muted">{{ $line->custom_spec }}</div>@endif
                @if ($line->returned_qty > 0)<div style="color: #b91c1c">Returned: {{ \App\Support\Quantity::format($line->returned_qty) }}</div>@endif
            </td>
            <td class="right">{{ \App\Support\Quantity::format($line->quantity) }}</td>
            <td class="right">{{ money($line->unit_price)->format() }}</td>
            <td class="right">{{ $line->discount_cents ? money($line->discount_cents)->format() : '—' }}</td>
            <td class="right">{{ money($line->line_total)->format() }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="margin-top: 10px">
    <tr>
        <td style="width: 52%; vertical-align: top">
            @if ($order->payments->isNotEmpty())
                <div class="label">Payments</div>
                @foreach ($order->payments as $p)
                    <div>{{ $p->business_date->format('d M Y') }} · {{ $p->method->name }} {{ $p->number }} · {{ money($p->amount)->format() }}@if ($p->cheque_status) ({{ $p->cheque_status->label() }})@endif</div>
                @endforeach
            @endif
            @if ($order->notes)<div style="margin-top: 6px"><span class="label">Notes</span><br>{{ $order->notes }}</div>@endif
        </td>
        <td style="width: 48%">
            <table class="totals">
                <tr><td>Subtotal</td><td class="right">{{ money($order->subtotal)->format() }}</td></tr>
                @if ($order->order_discount_cents)<tr><td>Discount</td><td class="right">− {{ money($order->order_discount_cents)->format() }}</td></tr>@endif
                @if ($order->delivery_charge)<tr><td>Delivery & services</td><td class="right">{{ money($order->delivery_charge)->format() }}</td></tr>@endif
                @if ($order->tax_rate_bp > 0)
                    <tr><td>VAT {{ \App\Support\BasisPoints::toPercent($order->tax_rate_bp) }}%{{ $order->prices_include_tax ? ' (included)' : '' }}</td><td class="right">{{ money($order->tax_total)->format() }}</td></tr>
                @endif
                @if ($order->rounding_cents)<tr><td>Rounding</td><td class="right">{{ money($order->rounding_cents)->format() }}</td></tr>@endif
                <tr class="grand"><td>Total</td><td class="right">{{ money($order->grand_total)->format() }}</td></tr>
                @if ($order->returned_total)<tr><td>Returned</td><td class="right">− {{ money($order->returned_total)->format() }}</td></tr>@endif
                <tr><td>Paid</td><td class="right">{{ money($order->paid_amount - $order->refunded_total)->format() }}</td></tr>
                <tr><td class="bold">Balance due</td><td class="right bold">{{ money($order->balance_due)->format() }}</td></tr>
            </table>
        </td>
    </tr>
</table>
@endsection

@section('terms')
    <div class="muted" style="margin-top: 4px">Goods sold may be returned within {{ $company->return_days ?? 7 }} days with this invoice. Warranty as stated per item.</div>
@endsection
