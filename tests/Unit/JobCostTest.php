<?php

namespace Tests\Unit;

use App\Services\Production\JobCost;
use PHPUnit\Framework\TestCase;

class JobCostTest extends TestCase
{
    public function test_labour_cost_calculates_from_minutes_and_hourly_rate_with_half_up_rounding(): void
    {
        // 90 minutes (1.5h) at Rs 1,200/hr (120000 cents) = 180000 cents
        $this->assertSame(180000, JobCost::labour(90, 120000));

        // 1 minute at 100 cents/hr = 1.666... -> 2 cents
        $this->assertSame(2, JobCost::labour(1, 100));

        // 30 minutes at 3 cents/hr = 1.5 -> 2 cents (half up)
        $this->assertSame(2, JobCost::labour(30, 3));
    }

    public function test_return_cost_computes_proportional_cost_with_half_up_rounding(): void
    {
        // Issued 3.000 m at 300000 cents total, returning 1.500 m = 150000 cents
        $this->assertSame(150000, JobCost::returnCost(300000, 3000, 1500));

        // Returning full remaining qty returns exact remaining cost
        $this->assertSame(99999, JobCost::returnCost(99999, 2500, 2500));

        // Zero net qty guarded
        $this->assertSame(0, JobCost::returnCost(1000, 0, 500));
    }

    public function test_total_sums_net_materials_labour_and_overhead_basis_points(): void
    {
        // Materials: +1,000,000 issued - 200,000 returned = 800,000
        // Labour: 300,000 + 100,000 = 400,000
        // Base = 1,200,000; overhead at 15% (1500 bp) = 180,000; total = 1,380,000
        $res = JobCost::total([1000000, -200000], [300000, 100000], 1500);

        $this->assertSame([
            'materials_cost' => 800000,
            'labour_cost' => 400000,
            'overhead_cost' => 180000,
            'total_cost' => 1380000,
        ], $res);
    }

    public function test_split_distributes_remainder_across_first_pieces(): void
    {
        $parts = JobCost::split(100, 3);

        $this->assertSame([34, 33, 33], $parts);
        $this->assertSame(100, array_sum($parts));
    }
}

