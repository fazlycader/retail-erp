<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receipt {{ $order->invoice_number ?? $order->number }}</title>
<style>
    @page { size: 80mm auto; margin: 0; }
    * { box-sizing: border-box; }
    body { width: 80mm; margin: 0; padding: 4mm 4mm 8mm; font: 12px/1.35 'Courier New', monospace; color: #000; }
    .c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
    .big { font-size: 15px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; padding: 1px 0; }
    .qr { width: 30mm; height: 30mm; }
    @media screen { body { margin: 16px auto; box-shadow: 0 0 0 1px #ddd; } .noprint { display: block; } }
    @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="c">
    @if ($logo)<img src="{{ $logo }}" style="max-width: 40mm; max-height: 18mm" alt=""><br>@endif
    <div class="b big">{{ $company->name }}</div>
    <div>{{ $location->name }}</div>
    @if ($location->address)<div>{{ $location->address }}</div>@endif
    @if ($location->phone)<div>Tel {{ \App\Rules\SriLankanPhone::display($location->phone) }}</div>@endif
    @if ($company->vat_registered && $company->vat_number)<div>VAT No {{ $company->vat_number }}</div>@endif
    @if ($locationSettings?->receipt_header)<div style="white-space: pre-line; margin-top: 4px">{{ $locationSettings->receipt_header }}</div>@endif
</div>
<hr>
<div class="c b">{{ strtoupper($title) }}</div>
<div>No: {{ $order->invoice_number ?? $order->number }}</div>
<div>Date: {{ ($order->confirmed_at ?? $order->created_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
@if ($order->salesperson)<div>Cashier: {{ $order->salesperson->name }}</div>@endif
@if ($order->customer)<div>Customer: {{ $order->customer->name }} {{ $order->customer->displayPhone() }}</div>@endif
<hr>
<table>
@foreach ($order->lines as $line)
    <tr><td colspan="2">{{ $line->name_snapshot }}</td></tr>
    <tr>
        <td>&nbsp;{{ \App\Support\Quantity::format($line->quantity) }} x {{ money($line->unit_price)->format() }}@if ($line->discount_cents) (-{{ money($line->discount_cents)->format() }})@endif</td>
        <td class="r">{{ money($line->line_total)->format() }}</td>
    </tr>
@endforeach
</table>
<hr>
<table>
    @if ($order->order_discount_cents)<tr><td>Discount</td><td class="r">-{{ money($order->order_discount_cents)->format() }}</td></tr>@endif
    @if ($order->tax_rate_bp > 0)<tr><td>VAT {{ \App\Support\BasisPoints::toPercent($order->tax_rate_bp) }}%{{ $order->prices_include_tax ? ' incl.' : '' }}</td><td class="r">{{ money($order->tax_total)->format() }}</td></tr>@endif
    @if ($order->rounding_cents)<tr><td>Rounding</td><td class="r">{{ money($order->rounding_cents)->format() }}</td></tr>@endif
    <tr class="b big"><td>TOTAL</td><td class="r">{{ money($order->grand_total)->format() }}</td></tr>
    @foreach ($order->payments as $p)
        <tr><td>{{ $p->method->name }}@if ($p->tendered) ({{ money($p->tendered)->format() }})@endif</td><td class="r">{{ money($p->amount)->format() }}</td></tr>
        @if ($p->change_given)<tr><td>Change</td><td class="r">{{ money($p->change_given)->format() }}</td></tr>@endif
    @endforeach
    @if ($order->balance_due)<tr class="b"><td>Balance due</td><td class="r">{{ money($order->balance_due)->format() }}</td></tr>@endif
</table>
<hr>
@if ($locationSettings?->receipt_footer)<div class="c" style="white-space: pre-line">{{ $locationSettings->receipt_footer }}</div>@endif
<div class="c" style="margin-top: 6px">
    <img class="qr" src="{{ $qr }}" alt=""><br>
    Scan for your digital copy
</div>
<div class="noprint c" style="margin-top: 12px"><button type="button" data-print>Print</button></div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelector('[data-print]')?.addEventListener('click', () => window.print());@if ($autoPrint) window.addEventListener('load', () => window.print());@endif</script>
</body>
</html>
