<?php

namespace App\Services\Sales;

use App\Support\BasisPoints;
use App\Support\Quantity;

/**
 * Pure, deterministic pricing engine shared by Quotations, Sales Orders, POS, and Web orders.
 *
 * Pipeline:
 * 1. Line gross (Quantity::times) → Line discount (amount or %, clamped to gross) → Line subtotal
 * 2. Subtotal of item lines → Order discount (amount or %, clamped to subtotal, distributed by largest remainder)
 * 3. Tax (inclusive or exclusive per settings, distributed across item lines by largest remainder)
 * 4. Delivery & service charges (service product lines + explicit delivery_charge)
 * 5. Cash rounding (nearest rounding_unit cents, half-up) → Grand total
 */
final class PricingService
{
    /**
     * @param  array<int, array{
     *     quantity: int,
     *     unit_price: int,
     *     discount_type?: string|null,
     *     discount_value?: int|null,
     *     is_service?: bool,
     * }>  $lines
     * @param  array{
     *     order_discount_type?: string|null,
     *     order_discount_value?: int|null,
     *     delivery_charge?: int|null,
     *     tax_rate_bp?: int|null,
     *     prices_include_tax?: bool,
     *     rounding_unit?: int|null,
     * }  $options
     * @return array{
     *     prices_include_tax: bool,
     *     tax_rate_bp: int,
     *     items_gross_total: int,
     *     line_discount_total: int,
     *     subtotal: int,
     *     order_discount_type: string|null,
     *     order_discount_value: int,
     *     order_discount_cents: int,
     *     net_subtotal: int,
     *     tax_total: int,
     *     service_lines_total: int,
     *     explicit_delivery_charge: int,
     *     delivery_charge: int,
     *     unrounded_total: int,
     *     rounding_cents: int,
     *     grand_total: int,
     *     total_discount_bp: int,
     *     lines: array<int, array{
     *         quantity: int,
     *         unit_price: int,
     *         is_service: bool,
     *         gross_cents: int,
     *         discount_type: string|null,
     *         discount_value: int,
     *         discount_cents: int,
     *         line_subtotal: int,
     *         order_discount_share: int,
     *         net_amount: int,
     *         tax_cents: int,
     *         line_total: int,
     *         effective_unit_price: int,
     *         effective_discount_bp: int,
     *     }>,
     * }
     */
    public function calculate(array $lines, array $options = []): array
    {
        $pricesIncludeTax = (bool) ($options['prices_include_tax'] ?? true);
        $taxRateBp = max(0, (int) ($options['tax_rate_bp'] ?? 0));
        $explicitDeliveryCharge = max(0, (int) ($options['delivery_charge'] ?? 0));
        $roundingUnit = max(0, (int) ($options['rounding_unit'] ?? 0));

        $orderDiscountType = $this->normalizeDiscountType($options['order_discount_type'] ?? null);
        $orderDiscountValue = max(0, (int) ($options['order_discount_value'] ?? 0));
        if ($orderDiscountType === 'percent') {
            $orderDiscountValue = min(10000, $orderDiscountValue);
        } elseif ($orderDiscountType === null) {
            $orderDiscountValue = 0;
        }

        $computedLines = [];
        $itemsGrossTotal = 0;
        $lineDiscountTotal = 0;
        $subtotal = 0;
        $serviceLinesTotal = 0;
        $itemIndices = [];
        $itemWeights = [];

        foreach ($lines as $idx => $raw) {
            $qty = max(0, (int) ($raw['quantity'] ?? 0));
            $unitPrice = max(0, (int) ($raw['unit_price'] ?? 0));
            $isService = (bool) ($raw['is_service'] ?? false);

            $grossCents = Quantity::times($qty, $unitPrice);

            $lineDiscType = $this->normalizeDiscountType($raw['discount_type'] ?? null);
            $lineDiscValue = max(0, (int) ($raw['discount_value'] ?? 0));

            if ($lineDiscType === 'percent') {
                $lineDiscValue = min(10000, $lineDiscValue);
                $rawDiscCents = BasisPoints::of($grossCents, $lineDiscValue);
            } elseif ($lineDiscType === 'amount') {
                $rawDiscCents = $lineDiscValue;
            } else {
                $lineDiscValue = 0;
                $rawDiscCents = 0;
            }

            $discountCents = min($grossCents, $rawDiscCents);
            $lineSubtotal = $grossCents - $discountCents;

            if ($isService) {
                $serviceLinesTotal += $lineSubtotal;
            } else {
                $itemsGrossTotal += $grossCents;
                $lineDiscountTotal += $discountCents;
                $subtotal += $lineSubtotal;
                $itemIndices[] = $idx;
                $itemWeights[] = $lineSubtotal;
            }

            $computedLines[$idx] = [
                ...$raw,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'is_service' => $isService,
                'gross_cents' => $grossCents,
                'discount_type' => $lineDiscType,
                'discount_value' => $lineDiscValue,
                'discount_cents' => $discountCents,
                'line_subtotal' => $lineSubtotal,
                'order_discount_share' => 0,
                'net_amount' => $lineSubtotal,
                'tax_cents' => 0,
                'line_total' => $lineSubtotal,
                'effective_unit_price' => $unitPrice,
                'effective_discount_bp' => 0,
            ];
        }

        // Order discount clamped to item subtotal
        if ($orderDiscountType === 'percent') {
            $rawOrderDiscount = BasisPoints::of($subtotal, $orderDiscountValue);
        } elseif ($orderDiscountType === 'amount') {
            $rawOrderDiscount = $orderDiscountValue;
        } else {
            $rawOrderDiscount = 0;
        }
        $orderDiscountCents = min($subtotal, $rawOrderDiscount);

        $orderDiscountShares = self::allocateByLargestRemainder($orderDiscountCents, $itemWeights);
        $netWeights = [];

        foreach ($itemIndices as $pos => $lineIdx) {
            $share = $orderDiscountShares[$pos] ?? 0;
            $computedLines[$lineIdx]['order_discount_share'] = $share;
            $computedLines[$lineIdx]['net_amount'] = $computedLines[$lineIdx]['line_subtotal'] - $share;
            $netWeights[$pos] = $computedLines[$lineIdx]['net_amount'];
        }

        $netSubtotal = $subtotal - $orderDiscountCents;

        // Tax calculation & largest-remainder distribution across item lines
        if ($taxRateBp > 0 && $netSubtotal > 0) {
            if ($pricesIncludeTax) {
                $denom = 10000 + $taxRateBp;
                $taxTotal = intdiv($netSubtotal * $taxRateBp * 2 + $denom, $denom * 2);
            } else {
                $taxTotal = BasisPoints::of($netSubtotal, $taxRateBp);
            }
        } else {
            $taxTotal = 0;
        }

        $taxShares = self::allocateByLargestRemainder($taxTotal, $netWeights);

        foreach ($itemIndices as $pos => $lineIdx) {
            $taxShare = $taxShares[$pos] ?? 0;
            $computedLines[$lineIdx]['tax_cents'] = $taxShare;
            $computedLines[$lineIdx]['line_total'] = $pricesIncludeTax
                ? $computedLines[$lineIdx]['net_amount']
                : ($computedLines[$lineIdx]['net_amount'] + $taxShare);
        }

        // Finalize effective unit price and effective discount bp on every line
        foreach ($computedLines as $idx => $line) {
            $qty = $line['quantity'];
            $gross = $line['gross_cents'];
            $totalLineDisc = $line['discount_cents'] + $line['order_discount_share'];

            $computedLines[$idx]['effective_unit_price'] = $qty > 0
                ? intdiv($line['net_amount'] * Quantity::SCALE + intdiv($qty, 2), $qty)
                : $line['unit_price'];

            $computedLines[$idx]['effective_discount_bp'] = $gross > 0
                ? intdiv($totalLineDisc * 10000 + intdiv($gross, 2), $gross)
                : 0;
        }

        $deliveryCharge = $explicitDeliveryCharge + $serviceLinesTotal;

        $unroundedTotal = ($pricesIncludeTax ? $netSubtotal : ($netSubtotal + $taxTotal)) + $deliveryCharge;

        if ($roundingUnit > 1 && $unroundedTotal > 0) {
            $grandTotal = intdiv($unroundedTotal + intdiv($roundingUnit, 2), $roundingUnit) * $roundingUnit;
            $roundingCents = $grandTotal - $unroundedTotal;
        } else {
            $grandTotal = $unroundedTotal;
            $roundingCents = 0;
        }

        $totalCombinedDiscount = $lineDiscountTotal + $orderDiscountCents;
        $totalDiscountBp = $itemsGrossTotal > 0
            ? intdiv($totalCombinedDiscount * 10000 + intdiv($itemsGrossTotal, 2), $itemsGrossTotal)
            : 0;

        return [
            'prices_include_tax' => $pricesIncludeTax,
            'tax_rate_bp' => $taxRateBp,
            'items_gross_total' => $itemsGrossTotal,
            'line_discount_total' => $lineDiscountTotal,
            'subtotal' => $subtotal,
            'order_discount_type' => $orderDiscountType,
            'order_discount_value' => $orderDiscountValue,
            'order_discount_cents' => $orderDiscountCents,
            'net_subtotal' => $netSubtotal,
            'tax_total' => $taxTotal,
            'service_lines_total' => $serviceLinesTotal,
            'explicit_delivery_charge' => $explicitDeliveryCharge,
            'delivery_charge' => $deliveryCharge,
            'unrounded_total' => $unroundedTotal,
            'rounding_cents' => $roundingCents,
            'grand_total' => $grandTotal,
            'total_discount_bp' => $totalDiscountBp,
            'lines' => array_values($computedLines),
        ];
    }

    /**
     * Allocate $amount integer cents across $weights using the largest remainder method.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function allocateByLargestRemainder(int $amount, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $shares = array_fill(0, $count, 0);
        $totalWeight = array_sum($weights);

        if ($amount <= 0 || $totalWeight <= 0) {
            return $shares;
        }

        $remainders = [];
        $allocated = 0;

        foreach ($weights as $i => $weight) {
            if ($weight <= 0) {
                $remainders[$i] = 0;

                continue;
            }
            $numerator = $amount * $weight;
            $floorShare = intdiv($numerator, $totalWeight);
            $shares[$i] = $floorShare;
            $remainders[$i] = $numerator % $totalWeight;
            $allocated += $floorShare;
        }

        $leftover = $amount - $allocated;
        if ($leftover > 0) {
            $indices = array_keys($weights);
            usort($indices, function (int $a, int $b) use ($remainders, $weights) {
                if ($remainders[$b] !== $remainders[$a]) {
                    return $remainders[$b] <=> $remainders[$a];
                }

                if ($weights[$b] !== $weights[$a]) {
                    return $weights[$b] <=> $weights[$a];
                }

                return $a <=> $b;
            });

            for ($k = 0; $k < $leftover && $k < count($indices); $k++) {
                $shares[$indices[$k]]++;
            }
        }

        return $shares;
    }

    private function normalizeDiscountType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $t = strtolower(trim($type));
        if ($t === 'fixed') {
            return 'amount';
        }

        return in_array($t, ['amount', 'percent'], true) ? $t : null;
    }
}
