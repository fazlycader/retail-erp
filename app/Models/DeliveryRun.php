<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['run_date' => 'date', 'start_odometer' => 'integer', 'end_odometer' => 'integer'];
    }

    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function loadingLocation(): BelongsTo { return $this->belongsTo(Location::class, 'loading_location_id'); }
    public function vehicle(): BelongsTo { return $this->belongsTo(DeliveryVehicle::class); }
    public function driver(): BelongsTo { return $this->belongsTo(User::class, 'driver_id'); }
    public function stops(): HasMany { return $this->hasMany(DeliveryStop::class)->orderBy('sort_order'); }
}
