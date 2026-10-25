<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $po->number }}</title>
</head>
<body style="font-family: sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="margin-bottom: 4px;">Purchase Order {{ $po->number }}</h2>
    <p style="margin-top: 0; color: #6b7280;">From {{ $company->name }}</p>

    <p>Dear {{ $po->supplier?->contact_person ?: $po->supplier?->name }},</p>

    <p>
        Please find attached our purchase order <strong>{{ $po->number }}</strong>
        for delivery to <strong>{{ $po->location?->name }} ({{ $po->location?->code }})</strong>
        @if($po->expected_date)
            by <strong>{{ $po->expected_date->format('Y-m-d') }}</strong>
        @endif.
    </p>

    @if($po->notes || $po->note)
        <p><strong>Notes:</strong> {{ $po->notes ?: $po->note }}</p>
    @endif

    <p>Total amount: <strong>{{ money($po->total_amount ?: $po->total) }}</strong></p>

    <p>Thank you,<br>{{ $company->name }}</p>
</body>
</html>
