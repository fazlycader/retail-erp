<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverCollection extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['amount' => 'integer', 'handed_over_at' => 'datetime']; }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function delivery(): BelongsTo { return $this->belongsTo(Delivery::class); }
    public function driver(): BelongsTo { return $this->belongsTo(User::class, 'driver_id'); }
    public function shift(): BelongsTo { return $this->belongsTo(Shift::class); }
}
