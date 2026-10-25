<?php

namespace App\Services\Reports\Concerns;

/** Card and table shapes shared by the report builders. */
trait BuildsReports
{
    /**
     * @return array<string, mixed>
     */
    public function card(string $key, string $label, int|float|string $value, string $format, int|float|null $previous = null): array
    {
        $change = $previous !== null && is_numeric($value) ? ($previous == 0 ? ($value == 0 ? 0.0 : null) : round(($value - $previous) * 100 / abs($previous), 1)) : null;

        return array_filter(['key' => $key, 'label' => $label, 'value' => $value, 'format' => $format, 'previous' => $previous], fn ($v) => $v !== null) + ['change' => $change];
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $columns  [key, label, format]
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function table(string $key, string $title, array $columns, array $rows, bool $totals = false): array
    {
        $columns = array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'format' => $c[2]], $columns);
        // Rows carry only what the columns show (no cost or profit riding along unseen).
        $keys = array_flip(array_column($columns, 'key'));
        $rows = array_map(fn ($r) => array_intersect_key($r, $keys), $rows);
        $total = null;

        if ($totals && $rows) {
            $total = [];
            foreach ($columns as $i => $column) {
                $total[$column['key']] = in_array($column['format'], ['money', 'count', 'quantity'], true)
                    ? array_sum(array_map(fn ($r) => (int) ($r[$column['key']] ?? 0), $rows))
                    : ($i === 0 ? 'Total' : null);
            }
        }

        return ['key' => $key, 'title' => $title, 'columns' => $columns, 'rows' => array_values($rows), 'totals' => $total];
    }
}
