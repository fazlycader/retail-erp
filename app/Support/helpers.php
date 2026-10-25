<?php

use App\Support\Money;

if (! function_exists('money')) {
    /**
     * Wrap an integer amount in cents as Money, e.g. money(125050)->format() => "LKR 1,250.50".
     */
    function money(int $cents, ?string $currency = null): Money
    {
        return Money::fromCents($cents, $currency);
    }
}
