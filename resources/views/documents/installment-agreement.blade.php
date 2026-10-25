<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 38px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.45; }
        h1 { margin: 0; font-size: 20px; }
        h2 { margin: 18px 0 7px; font-size: 12px; }
        .muted { color: #667085; }
        .header { border-bottom: 2px solid #3730a3; margin-bottom: 15px; padding-bottom: 10px; }
        .meta, .signatures { width: 100%; }
        .meta td { padding: 3px 0; vertical-align: top; width: 50%; }
        table.schedule { border-collapse: collapse; width: 100%; }
        .schedule th, .schedule td { border: 1px solid #d5d9e2; padding: 6px; text-align: right; }
        .schedule th:first-child, .schedule td:first-child { text-align: left; }
        .schedule th { background: #f1f2f7; }
        .terms { white-space: pre-wrap; }
        .signatures { margin-top: 42px; }
        .signatures td { padding: 25px 16px 0 0; width: 50%; }
        .line { border-top: 1px solid #344054; padding-top: 5px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }} — Installment Agreement</h1>
        <div class="muted">{{ $agreement->number }} · {{ $agreement->location?->name }} · {{ $agreement->start_date->format('Y-m-d') }}</div>
    </div>
    <table class="meta">
        <tr>
            <td><strong>Customer</strong><br>{{ $agreement->customer->name }}<br>NIC: {{ $agreement->customer->nic }}<br>{{ $agreement->customer->phone }}</td>
            <td><strong>Sale</strong><br>{{ $agreement->salesOrder->invoice_number ?? $agreement->salesOrder->number }}<br>Plan: {{ $agreement->plan->name }} · {{ $agreement->term_months }} months</td>
        </tr>
        <tr>
            <td>Down payment: <strong>{{ money($agreement->down_payment) }}</strong><br>Financed amount: <strong>{{ money($agreement->financed_amount) }}</strong></td>
            <td>Charges: <strong>{{ money($agreement->charges) }}</strong><br>Total payable: <strong>{{ money($agreement->total_payable) }}</strong></td>
        </tr>
        <tr>
            <td colspan="2">
                Charge tax treatment: {{ str_replace('_', ' ', $agreement->tax_treatment_snapshot) }}
                @if ($agreement->tax_cents > 0)
                    · Tax: {{ money($agreement->tax_cents) }} at {{ \App\Support\BasisPoints::toPercent($agreement->tax_rate_bp_snapshot) }}%
                @endif
                <br>
                Ownership {{ $agreement->ownership_until_paid_snapshot ? 'remains with the business until the agreement is fully paid' : 'transfers to the customer at sale' }}.
            </td>
        </tr>
    </table>

    @if ($agreement->guarantors->isNotEmpty())
        <h2>Guarantors</h2>
        @foreach ($agreement->guarantors as $guarantor)
            <p>{{ $guarantor->name }} · NIC {{ $guarantor->nic }} · {{ $guarantor->phone }} · {{ $guarantor->address }}</p>
        @endforeach
    @endif

    <h2>Repayment schedule</h2>
    <table class="schedule">
        <thead><tr><th>Due date</th><th>Principal</th><th>Charges</th><th>Amount due</th></tr></thead>
        <tbody>
        @foreach ($agreement->schedule as $line)
            <tr><td>{{ $line->due_date->format('Y-m-d') }}</td><td>{{ money($line->principal) }}</td><td>{{ money($line->charge) }}</td><td>{{ money($line->amount_due) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2>Agreement terms</h2>
    <div class="terms">{{ $agreement->agreement_terms_snapshot }}</div>

    <table class="signatures">
        <tr>
            <td><div class="line">Customer signature · {{ $agreement->customer->name }} · Date: __________________</div></td>
            <td><div class="line">For {{ $company->name }} · Staff signature · Date: __________________</div></td>
        </tr>
    </table>
</body>
</html>
