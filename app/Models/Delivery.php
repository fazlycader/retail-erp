<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToLocation;
use App\Models\Concerns\TracksSyncVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use BelongsToLocation, TracksSyncVersion;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_date' => 'date', 'status' => DeliveryStatus::class, 'requires_installation' => 'boolean', 'balance_to_collect' => 'integer', 'proof_photos' => 'array', 'arrived_at' => 'datetime', 'delivered_at' => 'datetime', 'sync_version' => 'integer'];
    }

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function zone(): BelongsTo { return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id'); }
    public function lines(): HasMany { return $this->hasMany(DeliveryLine::class); }
    public function stops(): HasMany { return $this->hasMany(DeliveryStop::class); }
    public function installations(): HasMany { return $this->hasMany(InstallationJob::class); }
    public function collections(): HasMany { return $this->hasMany(DriverCollection::class); }
}
