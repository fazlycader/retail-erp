@php $m = fn ($c) => $c === null ? '—' : money((int) $c)->format(); $t = $report['totals']; @endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>End of day {{ $report['business_date'] }}</title>
@include('documents._thermal-style')
</head>
<body>
<div class="c">
    <div class="b big">{{ $company->name }}</div>
    <div>{{ $report['location'] }}</div>
    <div class="b" style="margin-top: 4px">END OF DAY {{ $report['business_date'] }}</div>
    <div>{{ $closed ? 'Closed and locked' : 'NOT CLOSED (preview)' }}</div>
</div>
<hr>
<table>
    <tr><td>Invoices ({{ $t['invoices'] }})</td><td class="r">{{ $m($t['sales']) }}</td></tr>
    <tr><td>Returns ({{ $t['returns_count'] }})</td><td class="r">-{{ $m($t['returns']) }}</td></tr>
</table>
<hr>
<div class="b">Payments</div>
<table>
    @foreach ($report['payments'] as $p)<tr><td>{{ $p['method'] }} ({{ $p['count'] }})</td><td class="r">{{ $m($p['amount']) }}</td></tr>@endforeach
    <tr class="b"><td>Collected</td><td class="r">{{ $m($t['collected']) }}</td></tr>
    @foreach ($report['refunds'] as $r)<tr><td>Refunds {{ $r['method'] }}</td><td class="r">-{{ $m($r['amount']) }}</td></tr>@endforeach
    <tr class="b"><td>Net collected</td><td class="r">{{ $m($t['net_collected']) }}</td></tr>
</table>
<hr>
<div class="b">Shifts</div>
<table>
    @foreach ($report['shifts'] as $s)<tr><td>{{ $s['register'] }} · {{ $s['cashier'] }}</td><td class="r">{{ $s['variance'] === null ? 'open' : $m($s['variance']) }}</td></tr>@endforeach
    <tr class="b"><td>Total variance</td><td class="r">{{ $m($t['variance']) }}</td></tr>
</table>
@if ($report['deposits']['count'])
    <hr>
    <table><tr><td>Bank deposits ({{ $report['deposits']['count'] }})</td><td class="r">{{ $m($report['deposits']['cash'] + $report['deposits']['cheques']) }}</td></tr></table>
@endif
<hr>
<div class="c">Printed {{ now()->format('d M Y H:i') }}</div>
<div class="noprint c" style="margin-top: 12px"><button type="button" data-print>Print</button></div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelector('[data-print]')?.addEventListener('click', () => window.print());@if ($autoPrint) window.addEventListener('load', () => window.print());@endif</script>
</body>
</html>
