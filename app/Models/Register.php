<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A cash point (till) at a location. Every location gets "Main Counter" on create. */
#[Fillable(['location_id', 'name', 'is_active'])]
class Register extends Model
{
    use BelongsToLocation;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function openShift(): HasOne
    {
        return $this->hasOne(Shift::class, 'open_register_id');
    }
}
