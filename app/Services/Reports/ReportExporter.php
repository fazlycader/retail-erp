<?php

namespace App\Services\Reports;

use App\Support\Quantity;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes one report table to CSV or XLSX (openspout: streaming, light on shared hosting).
 * The rows are exactly the ones shown on screen; money is written in rupees with 2 decimals
 * (CSV as text "1250.50", XLSX as a number), so totals match the screen.
 */
final class ReportExporter
{
    /** Bigger exports run in the background (database queue). */
    public const SYNC_LIMIT = 5000;

    public function write(array $table, string $format, string $path, array $meta = []): int
    {
        $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);

        if ($meta) {
            $writer->addRow(Row::fromValues([$meta['title'] ?? '', $meta['period'] ?? '']));
            $writer->addRow(Row::fromValues([]));
        }

        $writer->addRow(Row::fromValues(array_column($table['columns'], 'label')));
        $count = 0;

        foreach ($table['rows'] as $row) {
            $writer->addRow(Row::fromValues($this->values($table['columns'], $row, $format)));
            $count++;
        }

        if (! empty($table['totals'])) {
            $writer->addRow(Row::fromValues($this->values($table['columns'], $table['totals'], $format)));
        }

        $writer->close();

        return $count;
    }

    private function values(array $columns, array $row, string $format): array
    {
        return array_map(function ($column) use ($row, $format) {
            $value = $row[$column['key']] ?? null;

            if ($value === null) {
                return '';
            }

            if ($column['format'] === 'money' && is_int($value)) {
                return $format === 'xlsx' ? $value / 100 : money($value)->toDecimal();
            }

            if ($column['format'] === 'quantity' && is_int($value)) {
                return $format === 'xlsx' ? $value / 1000 : Quantity::toDecimal($value);
            }

            return $value;
        }, $columns);
    }

    public static function filename(string $report, ReportRange $range, string $table, string $format): string
    {
        return "{$report}-{$table}-{$range->from}".($range->to !== $range->from ? "-to-{$range->to}" : '').".{$format}";
    }
}
