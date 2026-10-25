<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Reports\SummaryBuilder;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * (Re)builds the daily summary tables. Scheduled hourly for the last few business days, so late
 * returns and edits are picked up; run with --from/--to to rebuild history.
 */
#[Signature('reports:summarize {--days=3 : Business days before today to rebuild} {--from= : First date (Y-m-d)} {--to= : Last date (Y-m-d)}')]
#[Description('Build the daily report summaries')]
class SummarizeReports extends Command
{
    public function handle(SummaryBuilder $builder): int
    {
        foreach (Location::query()->pluck('id') as $locationId) {
            $today = CarbonImmutable::parse(BusinessDay::current($locationId));
            $from = $this->option('from') ? CarbonImmutable::parse($this->option('from')) : $today->subDays(max(1, (int) $this->option('days')));
            $to = $this->option('to') ? CarbonImmutable::parse($this->option('to')) : $today->subDay();
            $to = $to->min($today->subDay());

            if ($from->gt($to)) {
                continue;
            }

            foreach (CarbonPeriod::create($from, $to) as $date) {
                $builder->build($locationId, $date->toDateString());
            }

            $this->info("Location {$locationId}: {$from->toDateString()} → {$to->toDateString()}");
        }

        return self::SUCCESS;
    }
}
