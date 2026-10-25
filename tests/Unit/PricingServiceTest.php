<?php

use App\Services\Sales\PricingService;
use App\Support\Quantity;

test('pricing engine calculates tax-inclusive totals and largest-remainder shares', function () {
    $pricing = new PricingService;

    // 3 items at LKR 100.00 (10000 cents) each, order discount LKR 10.00 (1000 cents)
    // Net after order discount = 29000 cents.
    // 18% inclusive VAT (1800 bp): total tax = round(29000 * 1800 / 11800) = round(4423.7288) = 4424 cents.
    $result = $pricing->calculate([
        ['quantity' => Quantity::fromUnits(1), 'unit_price' => 10000],
        ['quantity' => Quantity::fromUnits(1), 'unit_price' => 10000],
        ['quantity' => Quantity::fromUnits(1), 'unit_price' => 10000],
    ], [
        'order_discount_type' => 'fixed',
        'order_discount_value' => 1000,
        'tax_rate_bp' => 1800,
        'prices_include_tax' => true,
    ]);

    expect($result['items_gross_total'])->toBe(30000)
        ->and($result['subtotal'])->toBe(30000)
        ->and($result['order_discount_cents'])->toBe(1000)
        ->and($result['net_subtotal'])->toBe(29000)
        ->and($result['tax_total'])->toBe(4424)
        ->and($result['grand_total'])->toBe(29000);

    // Largest remainder distribution of 1000 cents across 3 equal lines: 334 + 333 + 333 = 1000
    $discountShares = array_column($result['lines'], 'order_discount_share');
    expect(array_sum($discountShares))->toBe(1000)
        ->and($discountShares)->toBe([334, 333, 333]);

    // Sum of line tax_cents must equal tax_total (4424)
    $taxShares = array_column($result['lines'], 'tax_cents');
    expect(array_sum($taxShares))->toBe(4424);
});

test('pricing engine calculates tax-exclusive totals, line discounts, service lines and cash rounding', function () {
    $pricing = new PricingService;

    // Line 1: 2 units @ LKR 1,250.00 (125000 cents) = 250000, 10% (1000 bp) line discount = 25000 -> subtotal 225000
    // Line 2 (service product): 1 unit @ LKR 45.00 (4500 cents) -> service_lines_total = 4500
    // Order discount: 5% (500 bp) on merchandise subtotal (225000) = 11250 -> taxable 213750
    // 18% exclusive VAT on 213750 = round(213750 * 0.18) = 38475
    // Delivery charge: 3000 cents
    // Unrounded total = 213750 + 38475 + 4500 + 3000 = 259725
    // Cash rounding unit = 100 cents (nearest LKR 1.00) -> 259700 (rounding_cents = -25)
    $result = $pricing->calculate([
        [
            'quantity' => Quantity::fromUnits(2),
            'unit_price' => 125000,
            'discount_type' => 'percent',
            'discount_value' => 1000,
        ],
        [
            'quantity' => Quantity::fromUnits(1),
            'unit_price' => 4500,
            'is_service' => true,
        ],
    ], [
        'order_discount_type' => 'percent',
        'order_discount_value' => 500,
        'delivery_charge' => 3000,
        'tax_rate_bp' => 1800,
        'prices_include_tax' => false,
        'rounding_unit' => 100,
    ]);

    expect($result['items_gross_total'])->toBe(250000)
        ->and($result['line_discount_total'])->toBe(25000)
        ->and($result['subtotal'])->toBe(225000)
        ->and($result['order_discount_cents'])->toBe(11250)
        ->and($result['net_subtotal'])->toBe(213750)
        ->and($result['tax_total'])->toBe(38475)
        ->and($result['service_lines_total'])->toBe(4500)
        ->and($result['delivery_charge'])->toBe(7500)
        ->and($result['rounding_cents'])->toBe(-25)
        ->and($result['grand_total'])->toBe(259700);
});

test('pricing engine clamps excessive line and order discounts to available amounts', function () {
    $pricing = new PricingService;

    $result = $pricing->calculate([
        [
            'quantity' => Quantity::fromUnits(1),
            'unit_price' => 5000,
            'discount_type' => 'fixed',
            'discount_value' => 99999,
        ],
    ], [
        'order_discount_type' => 'fixed',
        'order_discount_value' => 50000,
    ]);

    expect($result['line_discount_total'])->toBe(5000)
        ->and($result['subtotal'])->toBe(0)
        ->and($result['order_discount_cents'])->toBe(0)
        ->and($result['grand_total'])->toBe(0);
});
