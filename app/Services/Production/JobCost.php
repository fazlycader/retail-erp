<?php

namespace App\Services\Production;

use App\Support\BasisPoints;

/**
 * Job cost maths (integer cents, half-up rounding):
 * materials = Σ issued − Σ returned; labour = Σ entries; overhead = (materials + labour) × overhead %;
 * total = materials + labour + overhead. A finished batch is split per piece by largest remainder.
 */
final class JobCost
{
    /** Labour for minutes at an hourly rate (cents), rounded half up. */
    public static function labour(int $minutes, int $hourlyRate): int
    {
        return intdiv($minutes * $hourlyRate * 2 + 60, 120);
    }

    /**
     * @param  array<int, int>  $materialCosts  signed (+ issue, − return)
     * @param  array<int, int>  $labourAmounts
     * @return array{materials_cost: int, labour_cost: int, overhead_cost: int, total_cost: int}
     */
    public static function total(array $materialCosts, array $labourAmounts, int $overheadBp): array
    {
        $materials = max(0, array_sum($materialCosts));
        $labour = array_sum($labourAmounts);
        $overhead = BasisPoints::of($materials + $labour, $overheadBp);

        return ['materials_cost' => $materials, 'labour_cost' => $labour, 'overhead_cost' => $overhead, 'total_cost' => $materials + $labour + $overhead];
    }

    /**
     * Value of returning part of what was issued: the issued cost in proportion, rounded half up.
     */
    public static function returnCost(int $netCost, int $netQty, int $returnQty): int
    {
        return $netQty <= 0 ? 0 : intdiv($netCost * $returnQty * 2 + $netQty, $netQty * 2);
    }

    /**
     * Split a total over n pieces so the parts add up exactly (the first ones take the remainder).
     *
     * @return array<int, int>
     */
    public static function split(int $total, int $pieces): array
    {
        $pieces = max(1, $pieces);
        $base = intdiv($total, $pieces);
        $rest = $total - $base * $pieces;

        return array_map(fn (int $i) => $base + ($i < $rest ? 1 : 0), range(0, $pieces - 1));
    }
}
