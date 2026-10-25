<?php

namespace App\Models;

use App\Enums\ReturnCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount' => 'integer',
            'condition' => ReturnCondition::class,
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function restockLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'restock_location_id');
    }
}
