<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationJob extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['scheduled_date' => 'date', 'checklist' => 'array', 'completion_photos' => 'array', 'completed_at' => 'datetime']; }
    public function delivery(): BelongsTo { return $this->belongsTo(Delivery::class); }
    public function run(): BelongsTo { return $this->belongsTo(DeliveryRun::class, 'delivery_run_id'); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
}
