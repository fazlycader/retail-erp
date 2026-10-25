<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Sri Lankan phone numbers: 07X XXX XXXX, +94 7X XXX XXXX, 0094…, or landlines like 011 234 5678.
 * Spaces, dashes and brackets are ignored. Use normalize() to store as +94XXXXXXXXX.
 */
class SriLankanPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize((string) $value) === null) {
            $fail('Enter a Sri Lankan phone number, e.g. 077 123 4567.');
        }
    }

    public static function normalize(string $value): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', $value);

        if (! preg_match('/^(?:\+94|0094|94|0)([1-9]\d{8})$/', $digits, $match)) {
            return null;
        }

        return '+94'.$match[1];
    }

    /**
     * +94771234567 → 077 123 4567 for display.
     */
    public static function display(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^\+94(\d{2})(\d{3})(\d{4})$/', $value, $m)) {
            return $value;
        }

        return "0{$m[1]} {$m[2]} {$m[3]}";
    }
}
