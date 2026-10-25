<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 38px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.4; }
        h1 { margin: 0; font-size: 20px; }
        h2 { margin: 18px 0 7px; font-size: 12px; }
        .muted { color: #667085; }
        .header { border-bottom: 2px solid #3730a3; margin-bottom: 15px; padding-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d5d9e2; padding: 6px; text-align: right; }
        th:first-child, td:first-child { text-align: left; }
        th { background: #f1f2f7; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }} — Installment Statement</h1>
        <div class="muted">{{ $customer->name }} · NIC: {{ $customer->nic }} · {{ $customer->phone }} · Printed {{ now()->toDateString() }}</div>
    </div>
    @foreach ($agreements as $agreement)
        <h2>{{ $agreement->number }} · {{ $agreement->status }} · {{ $agreement->location?->name }}</h2>
        <p>Sale {{ $agreement->salesOrder?->invoice_number ?? $agreement->salesOrder?->number }} · {{ $agreement->start_date->format('Y-m-d') }} · Total {{ money($agreement->total_payable) }}</p>
        <table>
            <thead><tr><th>Due date</th><th>Installment</th><th>Paid</th><th>Late fees due</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($agreement->schedule as $line)
                <tr>
                    <td>{{ $line->due_date->format('Y-m-d') }}</td>
                    <td>{{ money($line->amount_due) }}</td>
                    <td>{{ money($line->amount_paid) }}</td>
                    <td>{{ money(max(0, $line->late_fee_charged - $line->late_fee_paid)) }}</td>
                    <td>{{ $line->status }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
