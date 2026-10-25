<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transfer_id', 'product_variant_id', 'stock_unit_id', 'requested_qty', 'sent_qty', 'received_qty', 'unit_cost', 'discrepancy_reason', 'note', 'sales_order_line_id'])]
class TransferLine extends Model
{
    protected $attributes = [
        'sent_qty' => 0,
        'received_qty' => 0,
        'unit_cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'integer',
            'sent_qty' => 'integer',
            'received_qty' => 'integer',
            'unit_cost' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function discrepancyQty(): int
    {
        return max(0, $this->sent_qty - $this->received_qty);
    }
}
