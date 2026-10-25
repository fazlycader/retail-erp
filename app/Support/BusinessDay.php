<?php

namespace App\Support;

use App\Models\LocationSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The trading day something belongs to (Asia/Colombo). Before the location's
 * business_day_starts_at (e.g. 02:00) it still counts as the previous day, so a late close
 * stays on one day. Every business_date column and every daily report uses this.
 */
final class BusinessDay
{
    public static function for(CarbonInterface $at, ?string $dayStartsAt = '00:00'): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));

        return ($local->format('H:i') < ($dayStartsAt ?: '00:00') ? $local->subDay() : $local)->toDateString();
    }

    /** Today's (or $at's) business date for a location. */
    public static function current(int $locationId, ?CarbonInterface $at = null): string
    {
        return self::for($at ?? now(), LocationSetting::for($locationId)->business_day_starts_at);
    }

    /**
     * When a business date starts and ends, as UTC timestamps, for queries on timestamp columns.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(string $date, ?string $dayStartsAt = '00:00'): array
    {
        $start = CarbonImmutable::parse("{$date} ".($dayStartsAt ?: '00:00'), config('app.timezone'));

        return [$start->utc(), $start->addDay()->utc()];
    }
}
