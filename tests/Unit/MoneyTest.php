<?php

use App\Support\BasisPoints;
use App\Support\Money;

test('money formats integer cents', function () {
    expect(Money::fromCents(125050, 'LKR')->format())->toBe('LKR 1,250.50')
        ->and(Money::fromCents(5, 'LKR')->format())->toBe('LKR 0.05')
        ->and(Money::fromCents(-99900, 'LKR')->format())->toBe('-LKR 999.00')
        ->and(Money::fromCents(125050, 'LKR')->toDecimal())->toBe('1250.50');
});

test('money parses decimal strings without floats', function () {
    expect(Money::fromDecimal('1,250.5', 'LKR')->cents)->toBe(125050)
        ->and(Money::fromDecimal('0.10', 'LKR')->cents)->toBe(10)
        ->and(Money::fromDecimal('19.99', 'LKR')->cents)->toBe(1999)
        ->and(Money::fromDecimal(42, 'LKR')->cents)->toBe(4200);
});

test('money rejects invalid input', function (string $input) {
    Money::fromDecimal($input);
})->with(['abc', '1.234', ''])->throws(InvalidArgumentException::class);

test('money arithmetic stays in cents', function () {
    $price = Money::fromCents(1999, 'LKR');

    expect($price->multiply(3)->cents)->toBe(5997)
        ->and($price->add(Money::fromCents(1, 'LKR'))->cents)->toBe(2000)
        ->and($price->subtract(Money::fromCents(999, 'LKR'))->cents)->toBe(1000);
});

test('basis points convert to and from percentages', function () {
    expect(BasisPoints::fromPercent('18'))->toBe(1800)
        ->and(BasisPoints::fromPercent('18.5'))->toBe(1850)
        ->and(BasisPoints::fromPercent('0.25'))->toBe(25)
        ->and(BasisPoints::toPercent(1850))->toBe('18.5')
        ->and(BasisPoints::toPercent(1000))->toBe('10')
        ->and(BasisPoints::toPercent(25))->toBe('0.25');
});
