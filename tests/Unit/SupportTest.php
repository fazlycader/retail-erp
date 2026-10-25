<?php

use App\Rules\SriLankanPhone;
use App\Support\BasisPoints;
use App\Support\Quantity;

test('quantities are parsed and shown in milli-units', function () {
    expect(Quantity::parse('1.25'))->toBe(1250)
        ->and(Quantity::parse('3'))->toBe(3000)
        ->and(Quantity::parse('-0.5'))->toBe(-500)
        ->and(Quantity::parse('1,200.125'))->toBe(1200125)
        ->and(Quantity::parse('.5'))->toBe(500)
        ->and(Quantity::fromUnits(12))->toBe(12000)
        ->and(Quantity::toDecimal(1250))->toBe('1.25')
        ->and(Quantity::toDecimal(3000))->toBe('3')
        ->and(Quantity::toDecimal(-5))->toBe('-0.005')
        ->and(Quantity::format(1250000))->toBe('1,250')
        ->and(Quantity::format(1250, 'kg'))->toBe('1.25 kg');
});

test('bad quantities are refused', function (string $input) {
    expect(fn () => Quantity::parse($input))->toThrow(InvalidArgumentException::class);
})->with(['', 'abc', '1.2345', '1.2.3', '-']);

test('quantity times price rounds half up', function () {
    expect(Quantity::times(1500, 33333))->toBe(50000)      // 1.5 × 333.33 = 499.995 → 500.00
        ->and(Quantity::times(3000, 125050))->toBe(375150) // 3 × 1,250.50
        ->and(Quantity::times(333, 100))->toBe(33)         // 0.333 × 1.00 = 0.333 → 0.33
        ->and(Quantity::times(-1500, 33333))->toBe(-50000);
});

test('percentages are basis points', function () {
    expect(BasisPoints::fromPercent('18'))->toBe(1800)
        ->and(BasisPoints::fromPercent('18.5'))->toBe(1850)
        ->and(BasisPoints::toPercent(1850))->toBe('18.5')
        ->and(BasisPoints::toPercent(1800))->toBe('18');
});

test('Sri Lankan phone numbers are normalised', function () {
    expect(SriLankanPhone::normalize('077 123 4567'))->toBe('+94771234567')
        ->and(SriLankanPhone::normalize('+94 11 234 5678'))->toBe('+94112345678')
        ->and(SriLankanPhone::normalize('12345'))->toBeNull()
        ->and(SriLankanPhone::display('+94771234567'))->toBe('077 123 4567');
});
