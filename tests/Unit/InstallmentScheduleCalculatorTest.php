<?php

use App\Services\Installments\ScheduleCalculator;

test('splits cents equally and puts every rounding remainder in the last installment', function () {
    $schedule = app(ScheduleCalculator::class)->calculate(10001, 1000, 3, '2026-01-31');

    expect(array_column($schedule, 'principal'))->toBe([3333, 3333, 3335])
        ->and(array_column($schedule, 'charge'))->toBe([333, 333, 334])
        ->and(array_column($schedule, 'amount_due'))->toBe([3666, 3666, 3669])
        ->and(array_column($schedule, 'due_date'))->toBe(['2026-02-28', '2026-03-31', '2026-04-30'])
        ->and(array_sum(array_column($schedule, 'principal')))->toBe(10001)
        ->and(array_sum(array_column($schedule, 'charge')))->toBe(1000);
});
