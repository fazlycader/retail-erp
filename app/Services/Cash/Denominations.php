<?php

namespace App\Services\Cash;

/**
 * LKR notes and coins, in cents. Counts arrive as {"500000": 2, "100": 7} (denomination => count).
 */
final class Denominations
{
    public const NOTES = [500000, 200000, 100000, 50000, 10000, 5000, 2000];

    public const COINS = [1000, 500, 200, 100];

    /** @return array<int, int> */
    public static function all(): array
    {
        return [...self::NOTES, ...self::COINS];
    }

    /**
     * Keep only known denominations with a positive whole count.
     *
     * @return array<string, int>
     */
    public static function clean(?array $counts): array
    {
        $clean = [];

        foreach (self::all() as $value) {
            $count = (int) ($counts[$value] ?? $counts[(string) $value] ?? 0);

            if ($count > 0) {
                $clean[(string) $value] = min($count, 100000);
            }
        }

        return $clean;
    }

    public static function total(?array $counts): int
    {
        $total = 0;

        foreach (self::clean($counts) as $value => $count) {
            $total += (int) $value * $count;
        }

        return $total;
    }

    /** For screens: [{value, label, kind}] */
    public static function options(): array
    {
        return array_map(fn (int $v) => [
            'value' => $v,
            'label' => number_format($v / 100),
            'kind' => in_array($v, self::NOTES, true) ? 'note' : 'coin',
        ], self::all());
    }
}
