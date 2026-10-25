<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Quotation {{ $quotation->number }}</title>
</head>
<body style="font-family: sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="margin-bottom: 4px;">Quotation {{ $quotation->number }}</h2>
    <p style="margin-top: 0; color: #6b7280;">From {{ $company->name }}</p>

    <p>Dear {{ $quotation->customer?->name ?: 'Customer' }},</p>

    <p>
        Please find attached our quotation <strong>{{ $quotation->number }}</strong>
        valid until <strong>{{ $quotation->valid_until?->format('Y-m-d') }}</strong>.
    </p>

    @if($quotation->notes)
        <p><strong>Notes:</strong> {{ $quotation->notes }}</p>
    @endif

    <p>Grand Total: <strong>{{ money($quotation->grand_total) }}</strong></p>

    <p>Thank you,<br>{{ $company->name }}</p>
</body>
</html>

