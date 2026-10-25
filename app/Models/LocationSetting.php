<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per location: receipt texts, business-day cutoff, terminal auto-lock.
 * Read with LocationSetting::for($location) (created on first use, memoised per request).
 */
#[Fillable(['receipt_header', 'receipt_footer', 'business_day_starts_at', 'auto_lock_minutes'])]
class LocationSetting extends Model
{
    /** @var array<int, self> */
    private static array $memo = [];

    protected $attributes = [
        'business_day_starts_at' => '00:00',
        'auto_lock_minutes' => 5,
    ];

    protected function casts(): array
    {
        return [
            'auto_lock_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => self::$memo[$setting->location_id] = $setting);
        static::deleted(fn (self $setting) => self::forget());
    }

    public static function for(Location|int $location): self
    {
        $id = $location instanceof Location ? $location->id : $location;

        return self::$memo[$id] ??= static::query()->where('location_id', $id)->first()
            ?? tap((new static)->forceFill(['location_id' => $id]))->save();
    }

    /** Tests and long-running commands: drop memoised rows. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
