<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Rules\SriLankanPhone;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'code', 'type', 'address', 'phone', 'is_active'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every location has its settings row from the start.
        static::created(function (Location $location) {
            LocationSetting::for($location);
            Register::query()->create(['location_id' => $location->id, 'name' => 'Main Counter']);
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(LocationSetting::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active locations a user can work at: admins all of them, others their home + extra ones.
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        $query->active();

        return $user->role->seesAllLocations() ? $query : $query->whereKey($user->locationIds());
    }

    /**
     * @return array{id: int, name: string, code: string, type: string}
     */
    public function toOption(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'code' => $this->code, 'type' => $this->type->value];
    }

    public function phoneForDisplay(): ?string
    {
        return SriLankanPhone::display($this->phone);
    }
}
