<?php

namespace App\Services\GoLive;

use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use RuntimeException;
use Throwable;

/**
 * Base for the go-live importers (suppliers, customers, open orders, supplier balances).
 *
 * - Reads .xlsx or UTF-8 .csv with a header row (headers are matched case-insensitively, spaces → _).
 * - Dry run by default: every row is checked, nothing is written.
 * - Commit writes each row in its own transaction; a failing row never stops the others.
 * - Re-runnable: each imported row's natural key is stored in import_keys, so running the same
 *   file again skips what is already in.
 * - Writes a CSV report of every row that is not plain (skipped, failed, or created with a note).
 *
 * Never guesses: anything ambiguous fails with a reason instead of being "fixed".
 */
abstract class Importer
{
    abstract public function kind(): string;

    /** @return array<int, string> required headers */
    abstract public function required(): array;

    /** @return array<int, string> optional headers */
    abstract public function optional(): array;

    /**
     * Check every row (no writes). Group rows here if one record spans several lines.
     *
     * @param  array<int, array{line: int, row: array<string, string>}>  $rows
     * @return array<int, ImportRow>
     */
    abstract protected function check(array $rows, ImportContext $context): array;

    /** Write one checked row; return the record created (for import_keys). */
    abstract protected function apply(ImportRow $row, ImportContext $context): Model;

    /**
     * @return array{rows: array<int, ImportRow>, counts: array<string, int>, report: string}
     */
    public function run(string $path, User $actor, bool $commit, array $options = []): array
    {
        $context = new ImportContext($actor, $options, basename($path));
        $rows = $this->check($this->read($path), $context);

        // Rows already imported by an earlier run are skipped.
        $keys = array_values(array_filter(array_map(fn (ImportRow $r) => $r->key, $rows)));
        $done = $keys === [] ? [] : DB::table('import_keys')->where('kind', $this->kind())->whereIn('key', $keys)->pluck('key')->flip()->all();
        foreach ($rows as $row) {
            if ($row->key !== null && isset($done[$row->key]) && $row->status === ImportRow::CREATE) {
                $row->skip('Already imported by an earlier run.');
            }
        }

        if ($commit) {
            foreach ($rows as $row) {
                if ($row->status !== ImportRow::CREATE) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($row, $context) {
                        $model = $this->apply($row, $context);
                        if ($row->key !== null) {
                            DB::table('import_keys')->insert([
                                'kind' => $this->kind(), 'key' => $row->key,
                                'subject_type' => $model->getMorphClass(), 'subject_id' => $model->getKey(),
                                'source_file' => $context->file, 'created_at' => now(),
                            ]);
                        }
                    });
                } catch (Throwable $e) {
                    $row->fail('Not imported: '.$e->getMessage());
                }
            }
        }

        $counts = ['rows' => count($rows)];
        foreach ([ImportRow::CREATE, ImportRow::SKIP, ImportRow::FAIL] as $status) {
            $counts[$status] = count(array_filter($rows, fn (ImportRow $r) => $r->status === $status));
        }

        return ['rows' => $rows, 'counts' => $counts, 'report' => $this->report($rows, $commit)];
    }

    /**
     * @return array<int, array{line: int, row: array<string, string>}>
     */
    protected function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $reader = str_ends_with(strtolower($path), '.xlsx') ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $rows = [];
        $headers = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : trim((string) (is_scalar($c) ? $c : '')), $row->toArray());

                    if ($headers === null) {
                        $headers = array_map(fn ($h) => Str::snake(str_replace(['-', '/'], ' ', strtolower(trim(ltrim($h, "\u{FEFF}"))))), $cells);
                        $missing = array_diff($this->required(), $headers);
                        if ($missing !== []) {
                            throw new RuntimeException('Missing columns: '.implode(', ', $missing).'. Expected: '.implode(', ', [...$this->required(), ...$this->optional()]).'.');
                        }

                        continue;
                    }

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $values = [];
                    foreach ($headers as $i => $header) {
                        if ($header !== '') {
                            $values[$header] = $cells[$i] ?? '';
                        }
                    }
                    $rows[] = ['line' => $line, 'row' => $values];
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        if ($headers === null) {
            throw new RuntimeException('The file is empty.');
        }

        return $rows;
    }

    /**
     * @param  array<int, ImportRow>  $rows
     */
    private function report(array $rows, bool $commit): string
    {
        $dir = storage_path('app/private/go-live');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/'.$this->kind().'-'.now()->format('Ymd-His').'-'.($commit ? 'commit' : 'dry-run').'.csv';
        $columns = [...$this->required(), ...$this->optional()];

        $writer = new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['line', 'status', 'why', ...$columns]));

        foreach ($rows as $row) {
            if ($row->isPlain()) {
                continue;
            }
            $writer->addRow(Row::fromValues([implode(',', $row->lines), $row->status, $row->message(), ...array_map(fn ($c) => $row->raw[$c] ?? '', $columns)]));
        }

        $writer->close();

        return $path;
    }

    // ---- Parsing helpers (fail loudly, never guess) ------------------------------------------

    protected function money(ImportRow $row, string $field, bool $required = false): ?int
    {
        $value = str_replace([',', ' ', 'Rs.', 'Rs', 'LKR'], '', $row->raw[$field] ?? '');

        if ($value === '') {
            if ($required) {
                $row->fail("{$field} is required.");
            }

            return null;
        }

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            $row->fail("{$field} \"{$row->raw[$field]}\" is not an amount.");

            return null;
        }

        return Money::fromDecimal($value)->cents;
    }

    protected function date(ImportRow $row, string $field, bool $required = false): ?string
    {
        $value = trim($row->raw[$field] ?? '');

        if ($value === '') {
            if ($required) {
                $row->fail("{$field} is required.");
            }

            return null;
        }

        // Only unambiguous formats: 2026-10-31 or 31/10/2026 (day first, as in Sri Lanka).
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        $row->fail("{$field} \"{$value}\" is not a date (use 2026-10-31 or 31/10/2026).");

        return null;
    }
}
