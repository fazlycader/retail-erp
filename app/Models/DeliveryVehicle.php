<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryVehicle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['capacity_items' => 'integer', 'capacity_cubic_mm' => 'integer', 'odometer' => 'integer', 'is_active' => 'boolean'];
    }

    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function runs(): HasMany { return $this->hasMany(DeliveryRun::class, 'vehicle_id'); }
}
