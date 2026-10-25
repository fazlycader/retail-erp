@extends('documents.layout', ['number' => $order->number])

@section('meta')
    <div class="bold" style="font-size: 12px; margin-top: 4px">{{ $order->number }}</div>
    @if ($order->invoice_number)<div>Invoice: {{ $order->invoice_number }}</div>@endif
    <div>Printed: {{ now()->format('d M Y, H:i') }}</div>
@endsection

@section('content')
<table style="margin-top: 14px">
    <tr>
        <td style="width: 50%; padding-right: 6px">@include('documents._customer', ['customer' => $order->customer, 'address' => $order->delivery_address, 'heading' => 'Customer & delivery address'])</td>
        <td style="width: 50%; padding-left: 6px">
            <div class="box">
                <div class="label">Fulfilment</div>
                <div class="bold">{{ $order->fulfilment_type->label() }}</div>
                <div>From: {{ $order->location?->name }}</div>
                @if ($order->requested_delivery_date)<div>Requested date: {{ $order->requested_delivery_date->format('d M Y') }}</div>@endif
            </div>
        </td>
    </tr>
</table>
<table class="items">
    <thead><tr><th>SKU</th><th>Item</th><th class="right">Ordered</th><th class="right">Handed over</th></tr></thead>
    <tbody>
    @foreach ($order->lines->where('is_service', false) as $line)
        <tr>
            <td>{{ $line->sku_snapshot ?? 'CUSTOM' }}</td>
            <td>{{ $line->name_snapshot }}@if ($line->custom_spec)<div class="muted">{{ $line->custom_spec }}</div>@endif</td>
            <td class="right">{{ \App\Support\Quantity::format($line->quantity) }}</td>
            <td class="right bold">{{ \App\Support\Quantity::format($line->fulfilled_qty) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<table style="margin-top: 60px">
    <tr>
        <td style="width: 45%; border-top: 1px solid #9ca3af; padding-top: 4px">Issued / dispatched by</td>
        <td style="width: 10%"></td>
        <td style="width: 45%; border-top: 1px solid #9ca3af; padding-top: 4px">Received in good condition (customer)</td>
    </tr>
</table>
@endsection
