<?php

/*
| The system's maker (MVRK+). Shown as a small credit on receipts, invoices, printed documents,
| staff screens, reports and emails; the company's own brand always comes first.
| BRAND_CREDIT=false hides every credit (e.g. for a white-label client).
*/
return [
    'name' => env('BRAND_NAME', 'MVRK+'),
    'product' => env('BRAND_PRODUCT', 'MVRK+ ERP'),
    'website' => env('BRAND_WEBSITE', 'mvrk.lk'),
    'whatsapp' => env('BRAND_WHATSAPP', '+94 789 818 815'),
    'credit' => (bool) env('BRAND_CREDIT', true),
];
