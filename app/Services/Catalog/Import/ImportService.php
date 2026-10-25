<?php

namespace App\Services\Catalog\Import;

use App\Enums\ImportStatus;
use App\Jobs\RunCatalogImportStep;
use App\Models\Attribute;
use App\Models\AuditLog;
use App\Models\CatalogImport;
use App\Models\CatalogImportRow;
use App\Models\PriceChangeLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;

/**
 * Catalog import from XLSX/CSV: upload → map columns → dry run (every row: create / update /
 * unchanged / fail, with reasons) → commit. Same rules as the dental legacy-expense import:
 * nothing is guessed, failures are listed and never imported, re-running is safe. Variants are
 * matched by SKU; nothing is ever deleted; blank cells never clear a value.
 *
 * Small files run straight away; bigger ones are staged and processed in queued chunks
 * (config/catalog.php). Every commit row is checked again against the database first: if it
 * would now do something other than what the dry run said, it fails instead.
 */
class ImportService
{
    public const DISK = 'local';

    /** What a column of the template is called for each target. */
    public static function templateHeaders(): array
    {
        return [
            ...collect(ImportFields::all())->keys()->all(),
            ...Attribute::query()->orderBy('sort_order')->orderBy('name')->pluck('name')->all(),
        ];
    }

    public function upload(UploadedFile $file, User $user): CatalogImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('catalog-imports', Str::uuid().'.'.$extension, self::DISK);
        $absolute = Storage::disk(self::DISK)->path($path);

        try {
            $headers = $this->headers($absolute, $extension);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw new RuntimeException($e instanceof RuntimeException ? $e->getMessage() : 'That file could not be read. Save it as .xlsx or UTF-8 .csv and try again.');
        }

        return CatalogImport::query()->create([
            'user_id' => $user->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_path' => $path,
            'file_hash' => hash_file('sha256', $absolute),
            'status' => ImportStatus::Uploaded,
            'headers' => $headers,
            'mapping' => collect($headers)->map(fn ($h) => ImportFields::suggest($h))->all(),
            'options' => ['create_missing' => false],
        ]);
    }

    /**
     * Save the mapping and start the dry run.
     *
     * @param  array<int, ?string>  $mapping  column index => target key (null = ignore)
     */
    public function start(CatalogImport $import, array $mapping, bool $createMissing): void
    {
        $targets = ImportFields::targets();
        $mapping = collect($import->headers)->keys()->mapWithKeys(fn ($i) => [$i => filled($mapping[$i] ?? null) ? $mapping[$i] : null])->all();
        $used = array_filter($mapping);

        if (array_diff($used, array_keys($targets)) !== []) {
            throw new RuntimeException('Unknown column type in the mapping.');
        }

        if (! in_array('sku', $used, true)) {
            throw new RuntimeException('Map a column to SKU: it is how rows are matched.');
        }

        if (count($used) !== count(array_unique($used))) {
            $twice = collect($used)->duplicates()->map(fn ($key) => $targets[$key])->unique()->implode(', ');

            throw new RuntimeException("Each field can only come from one column ({$twice} is mapped twice).");
        }

        $import->rows()->delete();
        $import->update([
            'mapping' => $mapping,
            'options' => [...($import->options ?? []), 'create_missing' => $createMissing, 'inline' => $this->inline($import)],
            'status' => ImportStatus::Checking,
            'total_rows' => 0,
            'processed_rows' => 0,
            'counts' => null,
            'error' => null,
            'dry_run_at' => null,
        ]);

        $this->run($import, [new RunCatalogImportStep($import->id, 'stage')], null);
    }

    /** Read every data row of the file into catalog_import_rows (raw cells), then check them. */
    public function stage(CatalogImport $import): void
    {
        $absolute = Storage::disk(self::DISK)->path($import->file_path);

        if (! is_file($absolute) || hash_file('sha256', $absolute) !== $import->file_hash) {
            throw new RuntimeException('The uploaded file is missing or changed. Upload it again.');
        }

        $max = (int) config('catalog.import.max_rows');
        $batch = [];
        $count = 0;

        foreach ($this->dataRows($absolute, pathinfo($absolute, PATHINFO_EXTENSION)) as $rowNumber => $cells) {
            if (++$count > $max) {
                throw new RuntimeException("The file has more than {$max} rows. Split it into smaller files.");
            }

            $batch[] = [
                'catalog_import_id' => $import->id,
                'row_number' => $rowNumber,
                'action' => CatalogImportRow::FAIL, // until checked
                'raw' => json_encode($cells, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) === 500) {
                CatalogImportRow::query()->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            CatalogImportRow::query()->insert($batch);
        }

        if ($count === 0) {
            throw new RuntimeException('The file has a header row but no data rows.');
        }

        $import->update(['total_rows' => $count]);

        $jobs = $import->rows()->orderBy('id')->pluck('id')->chunk((int) config('catalog.import.chunk_rows'))
            ->map(fn (Collection $ids) => new RunCatalogImportStep($import->id, 'check', $ids->values()->all()))->all();

        $this->run($import, $jobs, 'check');
    }

    /**
     * @param  array<int, int>  $rowIds
     */
    public function checkRows(CatalogImport $import, array $rowIds): void
    {
        $checker = new ImportRowChecker($import);

        foreach (CatalogImportRow::query()->whereKey($rowIds)->orderBy('id')->get() as $row) {
            $result = $checker->check($row->raw ?? []);
            $row->update([
                'action' => $result['action'],
                'reasons' => $result['reasons'] ?: null,
                'data' => $result['data'] ?: null,
                'sku' => $result['sku'],
                'barcode' => $result['barcode'],
                'product_key' => $result['product_key'],
                'combination_key' => $result['combination_key'],
            ]);
        }

        CatalogImport::query()->whereKey($import->id)->increment('processed_rows', count($rowIds));
    }

    /** Checks that need all rows, then the counts. The dry run is ready after this. */
    public function finishCheck(CatalogImport $import): void
    {
        DB::transaction(function () use ($import) {
            $this->crossCheck($import);

            $import->update([
                'status' => ImportStatus::Checked,
                'counts' => $this->counts($import, 'action'),
                'processed_rows' => $import->total_rows,
                'dry_run_at' => now(),
            ]);
        });
    }

    public function commit(CatalogImport $import): void
    {
        if ($import->status !== ImportStatus::Checked) {
            throw new RuntimeException('Run the dry run first.');
        }

        $ids = $import->rows()->whereIn('action', [CatalogImportRow::CREATE, CatalogImportRow::UPDATE])
            ->orderBy('product_key')->orderBy('row_number')->pluck('id');

        $import->update(['status' => ImportStatus::Committing, 'processed_rows' => 0]);
        $import->rows()->update(['result' => null]);

        $jobs = $ids->chunk((int) config('catalog.import.chunk_rows'))
            ->map(fn (Collection $chunk) => new RunCatalogImportStep($import->id, 'commit', $chunk->values()->all()))->all();

        $this->run($import, $jobs, 'commit');
    }

    /**
     * @param  array<int, int>  $rowIds
     */
    public function commitRows(CatalogImport $import, array $rowIds): void
    {
        $checker = new ImportRowChecker($import);
        $writer = new ImportWriter($import);

        $rows = CatalogImportRow::query()->whereKey($rowIds)->orderBy('product_key')->orderBy('row_number')->get();

        foreach ($rows as $row) {
            // Fresh check against the database as it is now (another import or a person may have changed it).
            $fresh = $checker->check($row->raw ?? []);
            $expected = [$row->action];

            if ($row->action === CatalogImportRow::UPDATE) {
                $expected[] = CatalogImportRow::UNCHANGED;
            }

            if ($fresh['action'] === CatalogImportRow::FAIL || ! in_array($fresh['action'], $expected, true)) {
                $row->update([
                    'result' => 'failed',
                    'reasons' => $fresh['reasons'] ?: ["Changed since the dry run: this row would now be \"{$fresh['action']}\" instead of \"{$row->action}\". Run the dry run again."],
                ]);

                continue;
            }

            // A new product is created from all its rows' product columns (merged in the dry run).
            if ($fresh['action'] === CatalogImportRow::CREATE && $fresh['data']['product_id'] === null) {
                $fresh['data']['product'] = $row->data['product'];
                $fresh['data']['axes'] = $row->data['axes'];
            }

            try {
                $result = PriceChangeLog::from('import', fn () => $writer->write($fresh), "Import #{$import->id}, row {$row->row_number}");
                $row->update(['result' => $result]);
            } catch (Throwable $e) {
                report($e);
                $row->update(['result' => 'failed', 'reasons' => ['Not imported: '.Str::limit($e->getMessage(), 300)]]);
            }
        }

        CatalogImport::query()->whereKey($import->id)->increment('processed_rows', count($rowIds));
    }

    public function finishCommit(CatalogImport $import): void
    {
        $import->rows()->whereNull('result')->update(['result' => DB::raw("CASE WHEN action = 'fail' THEN 'failed' ELSE action END")]);
        $counts = $this->counts($import, 'result');

        $import->update([
            'status' => ImportStatus::Committed,
            'counts' => $counts,
            'processed_rows' => $import->total_rows,
            'committed_at' => now(),
        ]);

        AuditLog::record('catalog.import', $import, "Imported {$import->original_name}: {$counts['created']} created, {$counts['updated']} updated, {$counts['failed']} failed", null, $counts, $import->user);
    }

    public function markFailed(?CatalogImport $import, ?Throwable $e): void
    {
        $import?->update(['status' => ImportStatus::Failed, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong while processing the file. Try again, or upload it again.']);
    }

    /**
     * A blank template with every column (one sample row).
     *
     * @return string absolute path to a temporary file
     */
    public function template(string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-template-').'.'.$format;
        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($path);

        $headers = self::templateHeaders();
        $example = array_fill_keys($headers, '');
        $example = [...$example, 'sku' => 'SOFA-ROMA-GRY', 'product_slug' => 'roma-sofa', 'product_name' => 'Roma 3 seater sofa', 'category' => 'Living room > Sofas',
            'brand' => 'Damro', 'type' => 'stock', 'width_mm' => '2100', 'depth_mm' => '900', 'height_mm' => '850', 'requires_delivery' => 'yes',
            'warranty_months' => '12', 'price' => '185000', 'min_price' => '170000', 'publish_online' => 'yes'];

        $writer->addRow(Row::fromValues($headers));
        $writer->addRow(Row::fromValues(array_values($example)));
        $writer->close();

        return $path;
    }

    /**
     * Rows of the report CSV.
     *
     * @return \Generator<int, array<int, string>>
     */
    public function reportRows(CatalogImport $import): \Generator
    {
        yield ['row', 'sku', 'dry run', 'result', 'reasons', 'changes'];

        foreach ($import->rows()->orderBy('row_number')->lazy() as $row) {
            yield [
                (string) $row->row_number,
                (string) $row->sku,
                $row->action,
                (string) $row->result,
                implode(' ', $row->reasons ?? []),
                collect($row->data['changes'] ?? [])->map(fn ($c, $field) => "{$field}: ".json_encode($c[0]).' → '.json_encode($c[1]))->implode('; '),
            ];
        }
    }

    /** Text that a spreadsheet would run as a formula is prefixed with ' (CSV injection). */
    public static function csvText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /**
     * Run steps inline (small files) or as a queued batch, then the finishing step.
     *
     * @param  array<int, RunCatalogImportStep>  $jobs
     */
    private function run(CatalogImport $import, array $jobs, ?string $finish): void
    {
        $id = $import->id;
        $then = function () use ($id, $finish) {
            $import = CatalogImport::query()->find($id);

            if ($import && $import->status !== ImportStatus::Failed && $finish) {
                $finish === 'check' ? app(self::class)->finishCheck($import) : app(self::class)->finishCommit($import);
            }
        };

        if ($import->option('inline')) {
            try {
                foreach ($jobs as $job) {
                    app()->call([$job, 'handle']);
                }

                $then();
            } catch (Throwable $e) {
                report($e);
                $this->markFailed($import->fresh(), $e);
            }

            return;
        }

        if ($jobs === []) {
            $then();

            return;
        }

        Bus::batch($jobs)
            ->name("catalog-import-{$id}-".($finish ?? 'stage'))
            ->then($then)
            ->catch(fn ($batch, Throwable $e) => app(self::class)->markFailed(CatalogImport::query()->find($id), $e))
            ->dispatch();
    }

    private function inline(CatalogImport $import): bool
    {
        return Storage::disk(self::DISK)->size($import->file_path) <= (int) config('catalog.import.inline_max_bytes');
    }

    /**
     * Rules across rows. Every row involved fails, with the other row numbers in the reason.
     */
    private function crossCheck(CatalogImport $import): void
    {
        $rows = fn () => $import->rows()->where('action', '!=', CatalogImportRow::FAIL);
        $failures = [];
        $fail = function (Collection $group, string $reason) use (&$failures) {
            foreach ($group as $row) {
                $failures[$row->id][] = $reason;
            }
        };
        $numbers = fn (Collection $group) => $group->pluck('row_number')->sort()->implode(', ');

        // 1. The same SKU or barcode twice in the file.
        foreach (['sku' => 'SKU', 'barcode' => 'Barcode'] as $column => $label) {
            $duplicates = $import->rows()->whereNotNull($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->pluck($column);

            foreach ($duplicates as $value) {
                $group = $import->rows()->where($column, $value)->get(['id', 'row_number']);
                $fail($group, "{$label} {$value} is on more than one row (rows {$numbers($group)}).");
            }
        }

        // 2. Rows of one product: product columns must agree; new products need a name and a category,
        //    the same options on every row, and no two rows with the same options.
        foreach ($rows()->whereNotNull('product_key')->get(['id', 'row_number', 'product_key', 'combination_key', 'action', 'data'])->groupBy('product_key') as $key => $group) {
            $merged = [];

            foreach ($group as $row) {
                foreach ($row->data['product'] ?? [] as $field => $value) {
                    if (array_key_exists($field, $merged) && $merged[$field] != $value) {
                        $fail($group, "The rows of product \"{$key}\" (rows {$numbers($group)}) disagree on ".str_replace('_', ' ', $field).'.');

                        continue 3;
                    }

                    $merged[$field] = $value;
                }
            }

            $creates = $group->where('action', CatalogImportRow::CREATE);
            $isNew = $creates->isNotEmpty() && $creates->first()->data['product_id'] === null;

            if ($isNew) {
                if (! isset($merged['product_name']) || (! isset($merged['category_id']) && ! isset($merged['category_path']))) {
                    $fail($group, "New product \"{$key}\" needs a product name and a category on at least one of its rows.");

                    continue;
                }

                if ($creates->map(fn ($r) => json_encode($r->data['axes']))->unique()->count() > 1) {
                    $fail($group, "The rows of new product \"{$key}\" (rows {$numbers($group)}) don't all give the same options.");

                    continue;
                }

                if ($creates->first()->data['axes'] === [] && $creates->count() > 1) {
                    $fail($group, "New product \"{$key}\" has no options, so it can only have one variant (rows {$numbers($group)}). Add option columns (Colour, Size…) or give each row its own product.");

                    continue;
                }

                foreach ($creates as $row) {
                    $row->update(['data' => [...$row->data, 'product' => $merged]]);
                }
            }

            foreach ($creates->groupBy('combination_key')->filter(fn ($same) => $same->count() > 1) as $same) {
                $fail($same, "Rows {$numbers($same)} give product \"{$key}\" the same options twice.");
            }
        }

        foreach ($failures as $id => $reasons) {
            $row = CatalogImportRow::query()->find($id);
            $row->update(['action' => CatalogImportRow::FAIL, 'reasons' => array_values(array_unique([...($row->reasons ?? []), ...$reasons]))]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function counts(CatalogImport $import, string $column): array
    {
        $counts = $import->rows()->selectRaw("{$column} as k, COUNT(*) as n")->groupBy($column)->pluck('n', 'k')->map(fn ($n) => (int) $n);

        return $column === 'action'
            ? ['create' => $counts['create'] ?? 0, 'update' => $counts['update'] ?? 0, 'unchanged' => $counts['unchanged'] ?? 0, 'fail' => $counts['fail'] ?? 0]
            : ['created' => $counts['created'] ?? 0, 'updated' => $counts['updated'] ?? 0, 'unchanged' => $counts['unchanged'] ?? 0, 'failed' => $counts['failed'] ?? 0];
    }

    /**
     * @return array<int, string>
     */
    private function headers(string $path, string $extension): array
    {
        foreach ($this->rowsOf($path, $extension) as $cells) {
            $headers = array_map(fn ($cell, $i) => ($text = trim((string) (is_scalar($cell) ? $cell : ''))) !== '' ? $text : 'Column '.($i + 1), $cells, array_keys($cells));

            if (! array_filter($cells, fn ($c) => trim((string) (is_scalar($c) ? $c : '')) !== '')) {
                throw new RuntimeException('The first row must be the column headings.');
            }

            return $headers;
        }

        throw new RuntimeException('The file is empty.');
    }

    /**
     * Data rows keyed by their spreadsheet row number (header = row 1). Empty rows are skipped
     * but still counted, so the numbers match what people see in Excel.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function dataRows(string $path, string $extension): \Generator
    {
        $number = 0;

        foreach ($this->rowsOf($path, $extension) as $cells) {
            $number++;

            if ($number === 1) {
                continue;
            }

            if (! array_filter($cells, fn ($c) => $c !== null && trim((string) (is_scalar($c) ? $c : 'x')) !== '')) {
                continue;
            }

            foreach ($cells as $i => $cell) {
                if (is_string($cell) && ! mb_check_encoding($cell, 'UTF-8')) {
                    throw new RuntimeException("Row {$number} has text that isn't UTF-8. Save the file as .xlsx, or as \"CSV UTF-8\", and upload it again.");
                }

                if ($cell instanceof \DateTimeInterface) {
                    $cells[$i] = ['date' => $cell->format('Y-m-d')]; // refused by ImportFields::text()
                }
            }

            yield $number => $cells;
        }
    }

    /**
     * @return \Generator<int, array<int, mixed>>
     */
    private function rowsOf(string $path, string $extension): \Generator
    {
        $reader = $this->reader($path, strtolower($extension));
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    private function reader(string $path, string $extension): ReaderInterface
    {
        if ($extension === 'xlsx') {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

            return new XlsxReader($options);
        }

        $options = new CsvOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $first = (string) fgets(fopen($path, 'r'));
        // Excel in some regions saves CSV with semicolons.
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        if (! mb_check_encoding($first, 'UTF-8')) {
            throw new RuntimeException('This CSV isn\'t UTF-8. In Excel use "Save as → CSV UTF-8", or upload the .xlsx.');
        }

        return new CsvReader($options);
    }
}
