<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryLine extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['quantity' => 'integer']; }
    public function delivery(): BelongsTo { return $this->belongsTo(Delivery::class); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed(); }
}
