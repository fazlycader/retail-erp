<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryStop extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['sort_order' => 'integer', 'loaded_at' => 'datetime', 'returned_at' => 'datetime']; }
    public function run(): BelongsTo { return $this->belongsTo(DeliveryRun::class, 'delivery_run_id'); }
    public function delivery(): BelongsTo { return $this->belongsTo(Delivery::class); }
}
