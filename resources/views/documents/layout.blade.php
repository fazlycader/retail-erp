<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
@isset($pdfUrl)<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">@endisset
<title>{{ $title }} {{ $number ?? '' }}</title>
<style>
    @page { margin: 28px 34px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .head td { vertical-align: top; }
    .company { font-size: 15px; font-weight: bold; color: #111827; }
    .doc-title { font-size: 18px; font-weight: bold; color: #4338ca; text-transform: uppercase; letter-spacing: 1px; }
    .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #6b7280; margin-bottom: 3px; }
    .items { margin-top: 14px; }
    .items th { background: #eef2ff; color: #3730a3; font-size: 8.5px; text-transform: uppercase; letter-spacing: .4px; padding: 6px; text-align: left; }
    .items td { padding: 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    .totals td { padding: 3px 6px; }
    .totals .grand td { font-size: 12px; font-weight: bold; border-top: 1.5px solid #111827; padding-top: 6px; }
    .stamp { display: inline-block; border: 2px solid #b91c1c; color: #b91c1c; padding: 4px 10px; font-weight: bold; font-size: 13px; text-transform: uppercase; }
    .footer { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    .footer td { vertical-align: top; }
    @isset($pdfUrl)
    html { background: #f3f4f6; }
    body { max-width: 760px; margin: 0 auto; background: #fff; padding: 24px 20px; font-size: 13px; }
    .toolbar { max-width: 760px; margin: 0 auto; padding: 12px 20px; display: flex; justify-content: flex-end; }
    .toolbar a { background: #4f46e5; color: #fff; padding: 8px 14px; border-radius: 6px; text-decoration: none; font: 600 14px sans-serif; }
    @media (max-width: 600px) { .head td, table.footer td { display: block; width: 100% !important; text-align: left; } }
    @endisset
</style>
</head>
<body>
@isset($pdfUrl)<div class="toolbar"><a href="{{ $pdfUrl }}">Download PDF</a></div>@endisset
<table class="head">
    <tr>
        <td style="width: 60%">
            @if ($logo)
                <img src="{{ $logo }}" style="max-height: 54px; max-width: 180px; margin-bottom: 6px;" alt="">
            @endif
            <div class="company">{{ $company->name }}</div>
            @if ($location)
                <div>{{ $location->name }}@if ($location->address) · {{ $location->address }}@endif</div>
                @if ($location->phone)<div>Tel: {{ \App\Rules\SriLankanPhone::display($location->phone) }}</div>@endif
            @elseif ($company->address)
                <div>{{ $company->address }}</div>
            @endif
            @if ($company->email)<div>{{ $company->email }}</div>@endif
            @if ($company->vat_registered && $company->vat_number)<div class="bold">VAT No: {{ $company->vat_number }}</div>@endif
        </td>
        <td class="right" style="width: 40%">
            <div class="doc-title">{{ $title }}</div>
            @yield('meta')
        </td>
    </tr>
</table>

@yield('content')

<table class="footer">
    <tr>
        <td>
            @if ($locationSettings?->receipt_footer)
                <div style="white-space: pre-line">{{ $locationSettings->receipt_footer }}</div>
            @endif
            @yield('terms')
            @if ($digitalUrl)
                <div class="muted" style="margin-top: 6px">Digital copy: {{ $digitalUrl }}</div>
            @endif
        </td>
        @if ($qr)
            <td style="width: 90px" class="right"><img src="{{ $qr }}" style="width: 84px; height: 84px" alt=""></td>
        @endif
    </tr>
</table>
</body>
</html>
