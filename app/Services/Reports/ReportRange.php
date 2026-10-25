<?php

namespace App\Services\Reports;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Some locations + a business-date range (inclusive). `only` narrows it to specific dates (used to
 * read live data for the days the summary tables don't cover).
 */
final readonly class ReportRange
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last_30_days', 'this_year'];

    /**
     * @param  array<int, int>  $locationIds
     * @param  array<int, string>|null  $only
     */
    public function __construct(
        public array $locationIds,
        public string $from,
        public string $to,
        public ?array $only = null,
    ) {
        if ($from > $to) {
            throw new InvalidArgumentException('The start date is after the end date.');
        }
    }

    /**
     * @param  array<int, int>  $locationIds
     */
    public static function preset(array $locationIds, string $preset): self
    {
        $today = CarbonImmutable::parse(BusinessDay::current($locationIds[0] ?? 0));

        [$from, $to] = match ($preset) {
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek(), $today],
            'last_week' => [$today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'last_30_days' => [$today->subDays(29), $today],
            'this_year' => [$today->startOfYear(), $today],
            default => [$today, $today],
        };

        return new self($locationIds, $from->toDateString(), $to->toDateString());
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** @return array<int, string> */
    public function dates(): array
    {
        if ($this->only !== null) {
            return $this->only;
        }

        return array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($this->from, $this->to)));
    }

    /**
     * @param  array<int, string>  $dates
     */
    public function only(array $dates): self
    {
        return new self($this->locationIds, $this->from, $this->to, array_values($dates));
    }

    /**
     * @param  array<int, int>  $locationIds
     */
    public function at(array $locationIds): self
    {
        return new self(array_values($locationIds), $this->from, $this->to, $this->only);
    }

    /**
     * The period to compare with: the same weekday last week for one day, else the same number
     * of days just before.
     */
    public function previous(): self
    {
        $shift = $this->days() === 1 ? 7 : $this->days();

        return new self(
            $this->locationIds,
            CarbonImmutable::parse($this->from)->subDays($shift)->toDateString(),
            CarbonImmutable::parse($this->to)->subDays($shift)->toDateString(),
        );
    }

    public function isSingleDay(): bool
    {
        return $this->from === $this->to;
    }

    /** Limit the query to these business dates on $column. */
    public function apply(Builder|EloquentBuilder $query, string $column): Builder|EloquentBuilder
    {
        if ($this->only !== null) {
            // DATE() because SQLite stores date columns with a time part; works on MySQL too.
            return $query->whereIn(DB::raw("DATE({$column})"), $this->only === [] ? ['0000-00-00'] : $this->only);
        }

        return $this->from === $this->to
            ? $query->whereDate($column, $this->from)
            : $query->whereDate($column, '>=', $this->from)->whereDate($column, '<=', $this->to);
    }

    /** Limit the query to these locations and business dates. */
    public function scope(Builder|EloquentBuilder $query, string $dateColumn = 'business_date', string $locationColumn = 'location_id'): Builder|EloquentBuilder
    {
        return $this->apply($query->whereIn($locationColumn, $this->locationIds ?: [0]), $dateColumn);
    }

    /**
     * UTC start and end of the range by the clock (midnight to midnight in Asia/Colombo), for
     * records that only have a timestamp.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(): array
    {
        return [BusinessDay::window($this->from)[0], BusinessDay::window($this->to)[1]];
    }

    /**
     * @return array{from: string, to: string, days: int}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'days' => $this->days()];
    }
}
