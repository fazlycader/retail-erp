<?php

namespace App\Services\Reports;

use App\Models\DailyLocationSummary;
use App\Models\DailyVariantSummary;
use Illuminate\Support\Facades\DB;

/**
 * Builds the daily summary rows (daily_location_summaries + daily_variant_summaries) for one
 * location-day from the live SalesData queries. Run at end of day and hourly for the last days
 * (scheduler), and by `php artisan reports:summarize`.
 */
class SummaryBuilder
{
    public function __construct(private SalesData $sales) {}

    public function build(int $locationId, string $date): DailyLocationSummary
    {
        $range = new ReportRange([$locationId], $date, $date);
        $totals = $this->sales->totals($range);
        $variants = $this->sales->variants($range);

        return DB::transaction(function () use ($locationId, $date, $range, $totals, $variants) {
            $summary = DailyLocationSummary::query()->where('location_id', $locationId)->whereDate('business_date', $date)->first() ?? new DailyLocationSummary;
            $summary->forceFill([
                'location_id' => $locationId,
                'business_date' => $date,
                ...$totals,
                'by_method' => $this->sales->byMethod($range),
                'by_channel' => $this->sales->byChannel($range),
                'by_salesperson' => $this->sales->bySalesperson($range),
                'built_at' => now(),
            ])->save();

            DailyVariantSummary::query()->where('location_id', $locationId)->whereDate('business_date', $date)->delete();

            foreach ($variants->chunk(200) as $chunk) {
                DailyVariantSummary::query()->insert($chunk->map(fn ($row) => [...$row, 'business_date' => $date])->values()->all());
            }

            return $summary;
        });
    }
}
