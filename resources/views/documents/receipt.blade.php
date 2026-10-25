@extends('documents.layout', ['number' => $payment->number])

@section('meta')
    <div class="bold" style="font-size: 12px; margin-top: 4px">{{ $payment->number }}</div>
    <div>Date: {{ $payment->created_at->timezone(config('app.timezone'))->format('d M Y, H:i') }}</div>
    @if ($payment->salesOrder)<div>For: {{ $payment->salesOrder->invoice_number ?? $payment->salesOrder->number }}</div>@endif
@endsection

@section('content')
<table style="margin-top: 14px">
    <tr>
        <td style="width: 50%; padding-right: 6px">@include('documents._customer', ['customer' => $payment->customer, 'heading' => 'Received from'])</td>
        <td style="width: 50%; padding-left: 6px">
            <div class="box">
                <div class="label">Amount received</div>
                <div style="font-size: 16px" class="bold">{{ money($payment->amount)->format() }}</div>
                <div>{{ $payment->method->name }}@if ($payment->reference) · Ref {{ $payment->reference }}@endif</div>
                @if ($payment->tendered)<div>Tendered {{ money($payment->tendered)->format() }} · Change {{ money($payment->change_given)->format() }}</div>@endif
                @if ($payment->cheque_number)
                    <div>Cheque {{ $payment->cheque_number }} · {{ $payment->cheque_bank }}{{ $payment->cheque_branch ? ', '.$payment->cheque_branch : '' }} · dated {{ $payment->cheque_date?->format('d M Y') }}</div>
                    <div class="muted">Cheque payments are subject to realisation.</div>
                @endif
            </div>
        </td>
    </tr>
</table>
@if ($payment->status === \App\Enums\PaymentRecordStatus::Voided)
    <div style="margin-top: 10px"><span class="stamp">Voided</span> <span class="muted">{{ $payment->void_reason }}</span></div>
@elseif ($payment->cheque_status === \App\Enums\ChequeStatus::Bounced)
    <div style="margin-top: 10px"><span class="stamp">Cheque returned</span></div>
@endif
@if ($payment->salesOrder)
    <table class="totals" style="margin-top: 14px; width: 50%; margin-left: 50%">
        <tr><td>Invoice total</td><td class="right">{{ money($payment->salesOrder->grand_total)->format() }}</td></tr>
        <tr><td class="bold">Balance after this payment</td><td class="right bold">{{ money($payment->salesOrder->balance_due)->format() }}</td></tr>
    </table>
@endif
<div style="margin-top: 14px" class="muted">Received by {{ $payment->receivedBy?->name }}</div>
@endsection
