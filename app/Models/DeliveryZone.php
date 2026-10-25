<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryZone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['areas' => 'array', 'fee_cents' => 'integer', 'free_above_cents' => 'integer', 'lead_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
