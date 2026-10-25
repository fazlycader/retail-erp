<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quantities, revenue and cost per product variant (or custom item) per location per business date. */
class DailyVariantSummary extends Model
{
    public const COLUMNS = [
        'location_id', 'product_variant_id', 'product_id', 'category_id', 'name', 'sku', 'category',
        'quantity', 'revenue', 'revenue_ex_tax', 'discounts', 'cost',
        'returned_qty', 'returned_revenue', 'returned_revenue_ex_tax', 'returned_cost', 'last_sold_on',
    ];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'last_sold_on' => 'date'];
    }
}
