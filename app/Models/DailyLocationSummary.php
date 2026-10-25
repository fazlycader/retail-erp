<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A location's sales figures for one business date, built by Reports\SummaryBuilder from the live queries. */
class DailyLocationSummary extends Model
{
    /** Summed when days are combined. */
    public const TOTALS = [
        'invoices_count', 'items_gross', 'discounts', 'sales', 'tax', 'delivery', 'revenue_ex_tax', 'cost',
        'returns_count', 'returns_total', 'returns_revenue_ex_tax', 'returns_cost',
        'payments_count', 'collected', 'refunds', 'cancelled_count',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'by_method' => 'array',
            'by_channel' => 'array',
            'by_salesperson' => 'array',
            'built_at' => 'datetime',
            ...array_fill_keys(self::TOTALS, 'integer'),
        ];
    }
}
