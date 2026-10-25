<?php

namespace App\Services\Installments;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ScheduleCalculator
{
    /**
     * @return array<int, array{due_date: string, principal: int, charge: int, amount_due: int}>
     */
    public function calculate(int $principalCents, int $chargeCents, int $termMonths, string $startDate): array
    {
        if ($principalCents < 0 || $chargeCents < 0 || $termMonths < 1) {
            throw new InvalidArgumentException('Principal and charges must be non-negative and term must be at least one month.');
        }

        $start = CarbonImmutable::parse($startDate);
        $principalBase = intdiv($principalCents, $termMonths);
        $chargeBase = intdiv($chargeCents, $termMonths);
        $rows = [];

        for ($month = 1; $month <= $termMonths; $month++) {
            $principal = $principalBase + ($month === $termMonths ? $principalCents % $termMonths : 0);
            $charge = $chargeBase + ($month === $termMonths ? $chargeCents % $termMonths : 0);
            $rows[] = [
                'due_date' => $start->addMonthsNoOverflow($month)->toDateString(),
                'principal' => $principal,
                'charge' => $charge,
                'amount_due' => $principal + $charge,
            ];
        }

        return $rows;
    }
}
