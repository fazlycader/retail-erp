<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Percentages are stored as integer basis points (1% = 100 bp) so rates such as
 * VAT 18% or service charge 10% never touch floats. 18.5% => 1850.
 */
final class BasisPoints
{
    public static function fromPercent(string|int $percent): int
    {
        $percent = trim((string) $percent);

        if (! preg_match('/^(\d*)(?:\.(\d{0,2}))?$/', $percent, $m) || ($m[1] === '' && ($m[2] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid percentage [{$percent}].");
        }

        return (int) ($m[1] ?: '0') * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function toPercent(int $basisPoints): string
    {
        $percent = intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);

        return rtrim(rtrim($percent, '0'), '.');
    }

    /**
     * Apply basis points (10000 = 100%) to an integer cents amount, rounded half-up.
     */
    public static function of(int $cents, int $basisPoints): int
    {
        if ($cents === 0 || $basisPoints === 0) {
            return 0;
        }

        $product = $cents * $basisPoints;
        $val = intdiv(abs($product) * 2 + 10000, 20000);

        return $product < 0 ? -$val : $val;
    }
}
