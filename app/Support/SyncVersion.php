<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Per-location change counter for staff polling ("give me everything since version N").
 *
 * next() takes the location's "sync" counter, which stays locked until the surrounding
 * transaction commits, so versions become visible in the order they were handed out: a poller
 * can never see version 7 while version 6 is still uncommitted. Always call it inside a transaction.
 */
final class SyncVersion
{
    public const TYPE = 'sync';

    public static function next(int $locationId): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Sync versions must be taken inside a transaction.');
        }

        return Counter::next(self::TYPE, self::scope($locationId));
    }

    /**
     * Mark rows changed after a query-builder update (which skips model events).
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int>|int  $ids
     */
    public static function touch(string $model, array|int $ids, int $locationId): void
    {
        DB::transaction(function () use ($model, $ids, $locationId) {
            $model::query()->withoutGlobalScopes()->whereKey((array) $ids)->update(['sync_version' => self::next($locationId)]);
        });
    }

    public static function bump(Model $model): void
    {
        $locationId = (int) ($model->getAttribute('location_id') ?: CurrentLocation::id() ?: 1);
        $model->forceFill(['sync_version' => self::next($locationId)])->saveQuietly();
    }

    public static function current(int $locationId): int
    {
        return Counter::current(self::TYPE, self::scope($locationId));
    }

    private static function scope(int $locationId): string
    {
        return "location:{$locationId}";
    }
}
