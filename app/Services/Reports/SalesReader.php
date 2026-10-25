<?php

namespace App\Services\Reports;

use App\Models\DailyLocationSummary;
use App\Models\DailyVariantSummary;
use App\Support\BusinessDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales figures for any range: days before today that have a summary row are read from the
 * summary tables (fast for months and years); today and any day without a summary are calculated
 * live, per location. The result is the same either way (see ReportsTest).
 */
class SalesReader
{
    public function __construct(private SalesData $live) {}

    /**
     * Summary rows that cover part of the range, and per location the live range for the rest.
     *
     * @return array{0: Collection<int, DailyLocationSummary>, 1: array<int, ReportRange>}
     */
    private function split(ReportRange $range): array
    {
        $summaries = $range->scope(DailyLocationSummary::query())->get()
            ->filter(fn (DailyLocationSummary $s) => $s->business_date->toDateString() < BusinessDay::current($s->location_id))
            ->values();

        $live = [];
        foreach ($range->locationIds as $locationId) {
            $covered = $summaries->where('location_id', $locationId)->map(fn ($s) => $s->business_date->toDateString())->all();
            $rest = array_values(array_diff($range->dates(), $covered));

            if ($rest !== []) {
                $live[$locationId] = $range->at([$locationId])->only($rest);
            }
        }

        return [$summaries, $live];
    }

    /**
     * @return array<string, int>
     */
    public function totals(ReportRange $range): array
    {
        [$summaries, $live] = $this->split($range);
        $totals = array_fill_keys(DailyLocationSummary::TOTALS, 0);

        foreach ($summaries as $summary) {
            foreach (DailyLocationSummary::TOTALS as $key) {
                $totals[$key] += (int) $summary->{$key};
            }
        }

        foreach ($live as $part) {
            foreach ($this->live->totals($part) as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return $totals;
    }

    /**
     * @return array<int, array<string, int>> totals per location id
     */
    public function byLocation(ReportRange $range): array
    {
        $out = [];
        foreach ($range->locationIds as $locationId) {
            $out[$locationId] = $this->totals($range->at([$locationId]));
        }

        return $out;
    }

    /**
     * @return array<string, array<string, int>> totals per business date
     */
    public function perDay(ReportRange $range): array
    {
        [$summaries, $live] = $this->split($range);
        $out = [];
        $add = function (string $date, array $values) use (&$out) {
            foreach (DailyLocationSummary::TOTALS as $key) {
                $out[$date][$key] = ($out[$date][$key] ?? 0) + (int) ($values[$key] ?? 0);
            }
        };

        foreach ($summaries as $summary) {
            $add($summary->business_date->toDateString(), $summary->only(DailyLocationSummary::TOTALS));
        }

        foreach ($live as $part) {
            foreach ($this->live->perDay($part) as $date => $values) {
                $add($date, $values);
            }
        }

        foreach ($range->dates() as $date) {
            $out[$date] ??= array_fill_keys(DailyLocationSummary::TOTALS, 0);
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<int, array{id: int, name: string, count: int, amount: int, refunds: int}>
     */
    public function byMethod(ReportRange $range): array
    {
        [$summaries, $live] = $this->split($range);
        $rows = collect($summaries->flatMap(fn ($s) => $s->by_method ?? []));
        foreach ($live as $part) {
            $rows = $rows->merge($this->live->byMethod($part));
        }

        return $rows->groupBy('id')->map(fn ($g) => [
            'id' => $g->first()['id'],
            'name' => $g->first()['name'],
            'count' => $g->sum('count'),
            'amount' => $g->sum('amount'),
            'refunds' => $g->sum('refunds'),
        ])->sortByDesc('amount')->values()->all();
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function byChannel(ReportRange $range): array
    {
        return $this->mergeKeyed($range, 'by_channel', fn ($r) => $this->live->byChannel($r));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bySalesperson(ReportRange $range): array
    {
        [$summaries, $live] = $this->split($range);
        $rows = collect($summaries->flatMap(fn ($s) => array_values($s->by_salesperson ?? [])));
        foreach ($live as $part) {
            $rows = $rows->merge(array_values($this->live->bySalesperson($part)));
        }

        return $rows->groupBy(fn ($r) => (string) ($r['id'] ?? 0))->map(fn ($g) => [
            'id' => $g->first()['id'],
            'name' => $g->first()['name'],
            'invoices' => $g->sum('invoices'),
            'sales' => $g->sum('sales'),
            'revenue_ex_tax' => $g->sum('revenue_ex_tax'),
            'cost' => $g->sum('cost'),
        ])->sortByDesc('sales')->values()->all();
    }

    /**
     * Variant rows (all days merged) per location and variant.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function variants(ReportRange $range): Collection
    {
        [$summaries, $live] = $this->split($range);
        $rows = collect();

        foreach ($summaries->groupBy('location_id') as $locationId => $days) {
            $dates = $days->map(fn ($s) => $s->business_date->toDateString())->all();
            $rows = $rows->merge(
                DailyVariantSummary::query()->where('location_id', $locationId)->whereIn(DB::raw('DATE(business_date)'), $dates)->get()
                    ->map(fn ($r) => [...$r->only(DailyVariantSummary::COLUMNS), 'last_sold_on' => $r->last_sold_on?->toDateString()]),
            );
        }

        foreach ($live as $part) {
            $rows = $rows->merge($this->live->variants($part));
        }

        $sum = ['quantity', 'revenue', 'revenue_ex_tax', 'discounts', 'cost', 'returned_qty', 'returned_revenue', 'returned_revenue_ex_tax', 'returned_cost'];

        return $rows->groupBy(fn ($r) => $r['location_id'].'|'.($r['product_variant_id'] ?? 'custom:'.$r['name']))
            ->map(fn (Collection $g) => [
                ...collect($g->first())->except($sum)->all(),
                ...collect($sum)->mapWithKeys(fn ($k) => [$k => (int) $g->sum($k)])->all(),
                'last_sold_on' => $g->pluck('last_sold_on')->filter()->max(),
            ])->values();
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function mergeKeyed(ReportRange $range, string $column, callable $live): array
    {
        [$summaries, $rest] = $this->split($range);
        $out = [];
        $parts = [...$summaries->map(fn ($s) => $s->{$column} ?? [])->all(), ...array_map($live, array_values($rest))];

        foreach ($parts as $part) {
            foreach ($part as $key => $values) {
                foreach ($values as $field => $value) {
                    $out[$key][$field] = ($out[$key][$field] ?? 0) + $value;
                }
            }
        }

        return $out;
    }
}
