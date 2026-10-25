<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $movement->type->label() }} slip</title>
@include('documents._thermal-style')
</head>
<body>
<div class="c">
    <div class="b big">{{ $company->name }}</div>
    <div>{{ $movement->location?->name }}</div>
    <div class="b" style="margin-top: 4px">{{ strtoupper($movement->type->label()) }}</div>
</div>
<hr>
<table>
    <tr class="b big"><td>Amount</td><td class="r">{{ money($movement->amount)->format() }}</td></tr>
    <tr><td>Category</td><td class="r">{{ $movement->category_name ?? '—' }}</td></tr>
    <tr><td>Shift</td><td class="r">#{{ $movement->shift_id }}</td></tr>
    <tr><td>Date</td><td class="r">{{ $movement->created_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</td></tr>
    <tr><td>By</td><td class="r">{{ $movement->user?->name }}</td></tr>
    @if ($movement->approvedBy)<tr><td>Approved by</td><td class="r">{{ $movement->approvedBy->name }}</td></tr>@endif
</table>
<div style="margin-top: 6px">{{ $movement->reason }}</div>
<div style="margin-top: 28px">Signature: ____________________</div>
<div class="noprint c" style="margin-top: 12px"><button type="button" data-print>Print</button></div>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelector('[data-print]')?.addEventListener('click', () => window.print());@if ($autoPrint) window.addEventListener('load', () => window.print());@endif</script>
</body>
</html>
