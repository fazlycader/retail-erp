<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** One column of a workshop's jobs board (Cutting, Frame, Upholstery…), in sort order. */
#[Fillable(['location_id', 'name', 'sort_order', 'is_active'])]
class ProductionStage extends Model
{
    public const DEFAULTS = ['Cutting', 'Frame', 'Upholstery', 'Polishing', 'QC', 'Ready'];

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * A workshop's active stages in order, creating the default list the first time.
     *
     * @return Collection<int, self>
     */
    public static function forWorkshop(int $locationId)
    {
        if (! static::query()->where('location_id', $locationId)->exists()) {
            foreach (self::DEFAULTS as $i => $name) {
                static::query()->create(['location_id' => $locationId, 'name' => $name, 'sort_order' => ($i + 1) * 10]);
            }
        }

        return static::query()->where('location_id', $locationId)->where('is_active', true)->ordered()->get();
    }
}
