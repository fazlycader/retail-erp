@php
    $m = fn ($c) => money((int) $c)->format();
    $q = fn ($milli) => \App\Support\Quantity::format((int) $milli);
    $change = fn ($now, $before) => $before ? (($now - $before) >= 0 ? '+' : '').round(($now - $before) * 100 / abs($before), 1).'%' : '';
    $t = $r['totals'];
@endphp
<!doctype html>
<html>
<body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;padding:24px">
    <tr><td>
        <p style="margin:0;font-size:13px;color:#6b7280">{{ $company }}</p>
        <h1 style="margin:4px 0 0;font-size:20px">Sales for {{ \Carbon\CarbonImmutable::parse($r['business_date'])->format('l j F Y') }}</h1>
        @if ($r['unclosed'])
            <p style="margin:12px 0 0;padding:10px 12px;background:#fef3c7;border-radius:8px;font-size:13px;color:#92400e">
                <strong>Not closed:</strong> {{ implode(', ', $r['unclosed']) }}. Their figures may still change.
            </p>
        @endif
    </td></tr>
    <tr><td style="padding-top:16px">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
            <tr>
                <td style="padding:10px;background:#f9fafb;border-radius:8px" width="50%">
                    <div style="font-size:12px;color:#6b7280">Net sales</div>
                    <div style="font-size:22px;font-weight:bold">{{ $m($t['net_sales']) }}</div>
                    <div style="font-size:12px;color:#6b7280">{{ $change($t['net_sales'], $t['previous_net_sales']) }} vs {{ \Carbon\CarbonImmutable::parse($r['previous_date'])->format('D j M') }}</div>
                </td>
                <td width="8"></td>
                <td style="padding:10px;background:#f9fafb;border-radius:8px" width="50%">
                    <div style="font-size:12px;color:#6b7280">Collected</div>
                    <div style="font-size:22px;font-weight:bold">{{ $m($t['collected']) }}</div>
                    <div style="font-size:12px;color:#6b7280">{{ $t['invoices'] }} invoices · gross profit {{ $m($t['gross_profit']) }}</div>
                </td>
            </tr>
        </table>
    </td></tr>
    <tr><td style="padding-top:16px">
        <h2 style="font-size:15px;margin:0 0 6px">By location</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
            @foreach ($r['locations'] as $l)
                <tr>
                    <td style="padding:3px 0">{{ $l['name'] }}@unless ($l['closed']) <span style="color:#92400e">(not closed)</span>@endunless</td>
                    <td align="right">{{ $m($l['net_sales']) }} <span style="color:#6b7280;font-size:12px">{{ $change($l['net_sales'], $l['previous_net_sales']) }}</span></td>
                </tr>
            @endforeach
        </table>
    </td></tr>
    @if ($r['top_items'])
        <tr><td style="padding-top:16px">
            <h2 style="font-size:15px;margin:0 0 6px">Top items</h2>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
                @foreach ($r['top_items'] as $item)
                    <tr><td style="padding:3px 0">{{ $q($item['quantity']) }} × {{ $item['name'] }}</td><td align="right">{{ $m($item['revenue']) }}</td></tr>
                @endforeach
            </table>
        </td></tr>
    @endif
    <tr><td style="padding-top:16px">
        <h2 style="font-size:15px;margin:0 0 6px">Control</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">
            @forelse ($r['variances'] as $v)
                <tr><td style="padding:3px 0">{{ $v['location'] }} · {{ $v['cashier'] }}{{ $v['waiting'] ? ' (waiting for approval)' : '' }}</td><td align="right" style="{{ $v['variance'] < 0 ? 'color:#b91c1c;font-weight:bold' : '' }}">{{ $m($v['variance']) }}</td></tr>
            @empty
                <tr><td style="color:#6b7280">No cash differences.</td></tr>
            @endforelse
            <tr><td style="padding:3px 0">Low stock items now</td><td align="right">{{ $r['low_stock']['count'] }}</td></tr>
        </table>
        @if ($r['low_stock']['items'])
            <p style="margin:6px 0 0;font-size:12px;color:#6b7280">{{ collect($r['low_stock']['items'])->map(fn ($i) => "{$i['location']}: {$i['name']} ({$q($i['available'])})")->implode(' · ') }}</p>
        @endif
    </td></tr>
    <tr><td align="center" style="padding-top:20px">
        <a href="{{ $url }}" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;font-weight:bold;padding:10px 18px;border-radius:8px">Open the full report</a>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
