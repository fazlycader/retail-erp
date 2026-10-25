<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Quantities are integers in milli-units (thousandths of the unit): 1.25 kg = 1250, 3 pcs = 3000.
 * Never floats. Parse what people type with parse("1.25"), show it with format(1250, 'kg').
 */
final class Quantity
{
    public const SCALE = 1000;

    public const DECIMALS = 3;

    /** "1.25" → 1250, "-0.5" → -500, "1,200" → 1200000. Strings only, no float maths. */
    public static function parse(string|int $amount): int
    {
        $amount = str_replace([',', ' '], '', trim((string) $amount));

        if (! preg_match('/^(-)?(\d*)(?:\.(\d{0,'.self::DECIMALS.'}))?$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("\"{$amount}\" is not a quantity.");
        }

        $milli = (int) ($m[2] ?: '0') * self::SCALE + (int) str_pad($m[3] ?? '', self::DECIMALS, '0');

        return $m[1] === '-' ? -$milli : $milli;
    }

    public static function fromUnits(int $units): int
    {
        return $units * self::SCALE;
    }

    /** 1250 → "1.25", 3000 → "3" (for form inputs). */
    public static function toDecimal(int $milli): string
    {
        $abs = abs($milli);
        $fraction = rtrim(str_pad((string) ($abs % self::SCALE), self::DECIMALS, '0', STR_PAD_LEFT), '0');

        return ($milli < 0 ? '-' : '').intdiv($abs, self::SCALE).($fraction === '' ? '' : '.'.$fraction);
    }

    /** 1250000 → "1,250", 1250 with "kg" → "1.25 kg". */
    public static function format(int $milli, ?string $unit = null): string
    {
        $abs = abs($milli);
        $fraction = rtrim(str_pad((string) ($abs % self::SCALE), self::DECIMALS, '0', STR_PAD_LEFT), '0');
        $text = ($milli < 0 ? '-' : '').number_format(intdiv($abs, self::SCALE)).($fraction === '' ? '' : '.'.$fraction);

        return $unit === null || $unit === '' ? $text : "{$text} {$unit}";
    }

    /**
     * Line value: quantity × unit price, in cents, rounded half up (away from zero).
     * 1.5 pcs × Rs. 333.33 = Rs. 500.00 (49999.5 cents → 50000).
     */
    public static function times(int $milli, int $unitCents): int
    {
        $product = $milli * $unitCents;
        $value = intdiv(abs($product) * 2 + self::SCALE, self::SCALE * 2);

        return $product < 0 ? -$value : $value;
    }
}
