@php
    $fmt = function ($value, $format) {
        if ($value === null || $value === '') return '—';
        return match ($format) {
            'money' => is_int($value) ? money($value)->format(false) : $value,
            'count' => is_numeric($value) ? number_format((float) $value) : $value,
            'percent' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%' : $value,
            'quantity' => is_int($value) ? \App\Support\Quantity::format($value) : $value,
            'minutes' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 1), '0'), '.').' min' : $value,
            default => $value,
        };
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $meta['title'] }} · {{ $range->from }}{{ $range->to !== $range->from ? ' – '.$range->to : '' }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #111827; margin: 0; padding: 24px; font-size: 12px; }
        h1 { font-size: 20px; margin: 0; }
        h2 { font-size: 14px; margin: 22px 0 6px; }
        .muted { color: #6b7280; }
        .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 16px; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; }
        .card .v { font-size: 16px; font-weight: 700; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #4b5563; }
        td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tfoot td { font-weight: 700; border-top: 2px solid #111827; }
        .toolbar { position: sticky; top: 0; background: #0f172a; padding: 8px; margin: -24px -24px 16px; text-align: center; }
        .toolbar button { font: 600 14px system-ui; color: #fff; background: #4f46e5; border: 0; border-radius: 8px; padding: 6px 14px; cursor: pointer; }
        @media print { .toolbar { display: none; } body { padding: 0; } h2 { break-after: avoid; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<div class="toolbar"><button type="button" data-print>Print / Save as PDF</button></div>
<p class="muted">{{ $company }} · {{ $locations }}</p>
<h1>{{ $meta['title'] }}</h1>
<p class="muted">Business dates {{ $range->from }}{{ $range->to !== $range->from ? ' to '.$range->to : '' }} · printed {{ now()->setTimezone(config('app.timezone'))->format('d M Y g:i A') }}</p>

@if (! empty($data['cards']))
    <div class="cards">
        @foreach ($data['cards'] as $card)
            <div class="card">
                <div class="muted">{{ $card['label'] }}</div>
                <div class="v">{{ $fmt($card['value'], $card['format']) }}</div>
                @if (isset($card['change']) && $card['change'] !== null)
                    <div class="muted">{{ $card['change'] >= 0 ? '+' : '' }}{{ $card['change'] }}% vs previous</div>
                @endif
            </div>
        @endforeach
    </div>
@endif

@foreach ($data['notes'] ?? [] as $note)
    <p class="muted">{{ $note }}</p>
@endforeach

@foreach ($data['tables'] as $table)
    <h2>{{ $table['title'] }}</h2>
    @if (empty($table['rows']))
        <p class="muted">Nothing in this period.</p>
    @else
        <table>
            <thead><tr>@foreach ($table['columns'] as $c)<th class="{{ in_array($c['format'], ['money', 'count', 'percent', 'minutes', 'number', 'quantity']) ? 'n' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($table['rows'] as $row)
                    <tr>@foreach ($table['columns'] as $c)<td class="{{ in_array($c['format'], ['money', 'count', 'percent', 'minutes', 'number', 'quantity']) ? 'n' : '' }}">{{ $fmt($row[$c['key']] ?? null, $c['format']) }}</td>@endforeach</tr>
                @endforeach
            </tbody>
            @if (! empty($table['totals']))
                <tfoot><tr>@foreach ($table['columns'] as $c)<td class="{{ in_array($c['format'], ['money', 'count', 'percent', 'minutes', 'number', 'quantity']) ? 'n' : '' }}">{{ $table['totals'][$c['key']] === null ? '' : $fmt($table['totals'][$c['key']], $c['format']) }}</td>@endforeach</tr></tfoot>
            @endif
        </table>
    @endif
@endforeach
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelector('[data-print]')?.addEventListener('click', () => window.print());</script>
</body>
</html>
