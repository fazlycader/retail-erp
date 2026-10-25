@php
    $m = fn ($c) => $c === null ? '—' : money((int) $c)->format();
    $s = $report['shift'];
    $c = $report['cash'];
    $at = fn ($iso) => $iso ? \Carbon\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d M H:i') : '';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $report['kind'] === 'z' ? 'Z-report' : 'X-report' }} #{{ $s['id'] }}</title>
@include('documents._thermal-style')
</head>
<body>
<div class="c">
    <div class="b big">{{ $company->name }}</div>
    <div>{{ $s['location'] ?? '' }}</div>
    <div class="b" style="margin-top: 4px">{{ $report['kind'] === 'z' ? 'Z-REPORT (SHIFT CLOSE)' : 'X-REPORT (NOT CLOSED)' }}</div>
</div>
<hr>
<table>
    <tr><td>Register</td><td class="r">{{ $s['register'] }}</td></tr>
    <tr><td>Cashier</td><td class="r">{{ $s['cashier'] }}</td></tr>
    <tr><td>Business date</td><td class="r">{{ $s['business_date'] }}</td></tr>
    <tr><td>Opened</td><td class="r">{{ $at($s['opened_at']) }}</td></tr>
    @if ($s['closed_at'])<tr><td>Closed</td><td class="r">{{ $at($s['closed_at']) }}</td></tr>@endif
</table>
<hr>
<div class="b">Payments</div>
<table>
    @foreach ($report['payments'] as $p)
        <tr><td>{{ $p['method'] }} ({{ $p['count'] }})</td><td class="r">{{ $m($p['amount']) }}</td></tr>
    @endforeach
    <tr class="b"><td>Collected</td><td class="r">{{ $m($report['collected']) }}</td></tr>
    <tr><td>Refunds</td><td class="r">-{{ $m($report['refunds']) }}</td></tr>
    <tr class="b"><td>Net</td><td class="r">{{ $m($report['net_collected']) }}</td></tr>
    <tr><td>Orders paid</td><td class="r">{{ $report['orders_count'] }}</td></tr>
    @if ($report['cheques']['count'])<tr><td>Cheques ({{ $report['cheques']['count'] }})</td><td class="r">{{ $m($report['cheques']['amount']) }}</td></tr>@endif
</table>
<hr>
<div class="b">Cash drawer</div>
<table>
    <tr><td>Opening float</td><td class="r">{{ $m($c['opening_float']) }}</td></tr>
    <tr><td>+ Cash payments</td><td class="r">{{ $m($c['cash_payments']) }}</td></tr>
    <tr><td>+ Pay-ins</td><td class="r">{{ $m($c['pay_ins']) }}</td></tr>
    <tr><td>- Cash refunds</td><td class="r">{{ $m($c['cash_refunds']) }}</td></tr>
    <tr><td>- Pay-outs</td><td class="r">{{ $m($c['pay_outs']) }}</td></tr>
    <tr><td>- Cash drops</td><td class="r">{{ $m($c['cash_drops']) }}</td></tr>
    @isset($c['expected'])<tr class="b"><td>Expected cash</td><td class="r">{{ $m($c['expected']) }}</td></tr>@endisset
    @if ($report['counted'] !== null)
        <tr class="b"><td>Counted cash</td><td class="r">{{ $m($report['counted']) }}</td></tr>
        <tr class="b"><td>{{ $report['variance'] < 0 ? 'SHORT' : ($report['variance'] > 0 ? 'OVER' : 'Variance') }}</td><td class="r">{{ $m($report['variance']) }}</td></tr>
    @endif
</table>
@if ($report['movements'])
    <hr>
    <table>
        @foreach ($report['movements'] as $mv)
            <tr><td>{{ $mv['label'] }}: {{ $mv['category'] ?? $mv['reason'] }}</td><td class="r">{{ $mv['type'] === 'pay_in' ? '' : '-' }}{{ $m($mv['amount']) }}</td></tr>
        @endforeach
    </table>
@endif
@foreach ($report['terminal'] as $t)
    <div>Terminal #{{ $t['id'] }}: {{ $m($t['terminal']) }} ({{ $t['difference'] >= 0 ? '+' : '' }}{{ $m($t['difference']) }})</div>
@endforeach
@if ($report['note'])<div>Note: {{ $report['note'] }}</div>@endif
@if ($report['approved_by'])<div>Approved by {{ $report['approved_by'] }}{{ $report['approval_note'] ? ': '.$report['approval_note'] : '' }}</div>@endif
@if ($report['force_close_reason'])<div>Force closed: {{ $report['force_close_reason'] }}</div>@endif
<hr>
<div class="c">Printed {{ now()->format('d M Y H:i') }}</div>
<div class="noprint c" style="margin-top: 12px"><button type="button" data-print>Print</button></div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelector('[data-print]')?.addEventListener('click', () => window.print());@if ($autoPrint) window.addEventListener('load', () => window.print());@endif</script>
</body>
</html>
