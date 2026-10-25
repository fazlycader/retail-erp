<?php

namespace App\Models\Concerns;

use App\Support\CurrentLocation;
use App\Support\SyncVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Rows staff screens poll for: every save takes a new sync_version from its location's counter.
 * Saves run in a transaction so the version lock covers the write.
 * Query-builder updates must call SyncVersion::touch() themselves.
 */
trait TracksSyncVersion
{
    public static function bootTracksSyncVersion(): void
    {
        static::saving(function ($model) {
            // "saving" fires before BelongsToLocation's "creating" hook, so fill the location the same way.
            $model->location_id ??= CurrentLocation::id() ?? Auth::user()?->home_location_id;
            $model->sync_version = SyncVersion::next($model->location_id);
        });
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }
}
