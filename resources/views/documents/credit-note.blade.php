@extends('documents.layout', ['number' => $note->number])

@section('meta')
    <div class="bold" style="font-size: 12px; margin-top: 4px">{{ $note->number }}</div>
    <div>Date: {{ $note->created_at->timezone(config('app.timezone'))->format('d M Y') }}</div>
    @if ($note->salesOrder)<div>Against: {{ $note->salesOrder->invoice_number ?? $note->salesOrder->number }}</div>@endif
    <div>{{ $note->expires_on ? 'Valid until '.$note->expires_on->format('d M Y') : 'Does not expire' }}</div>
@endsection

@section('content')
<table style="margin-top: 14px">
    <tr>
        <td style="width: 50%; padding-right: 6px">@include('documents._customer', ['customer' => $note->customer])</td>
        <td style="width: 50%; padding-left: 6px">
            <div class="box">
                <div class="label">Credit</div>
                <div style="font-size: 16px" class="bold">{{ money($note->amount)->format() }}</div>
                <div>Remaining: {{ money($note->remaining)->format() }} · {{ $note->status->label() }}</div>
                @if ($note->reason)<div class="muted">{{ $note->reason }}</div>@endif
            </div>
        </td>
    </tr>
</table>
@if ($note->salesReturn)
    <table class="items">
        <thead><tr><th>Returned item</th><th class="right">Qty</th><th>Condition</th><th class="right">Value</th></tr></thead>
        <tbody>
        @foreach ($note->salesReturn->lines as $line)
            <tr>
                <td>{{ $line->orderLine?->name_snapshot }}</td>
                <td class="right">{{ \App\Support\Quantity::format($line->quantity) }}</td>
                <td>{{ $line->condition->label() }}</td>
                <td class="right">{{ money($line->amount)->format() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
<div style="margin-top: 14px" class="muted">Issued by {{ $note->issuedBy?->name }}. Present this credit note (or its number) as payment at any of our showrooms.</div>
@endsection
