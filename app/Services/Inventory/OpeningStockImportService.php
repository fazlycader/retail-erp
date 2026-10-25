<?php

namespace App\Services\Inventory;

use App\Enums\ImportStatus;
use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\OpeningStockImport;
use App\Models\OpeningStockImportRow;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\UploadedFile;
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
 * Opening stock import from XLSX/CSV per location:
 * Columns: sku, quantity, unit_cost, serials (optional comma/newline-separated serial numbers for serialized variants).
 * Dry run first (checks SKU existence, non-service product, quantity > 0, unit_cost >= 0, serial count & uniqueness),
 * then commit to create `opening` stock movements and `stock_units`.
 */
class OpeningStockImportService
{
    public const DISK = 'local';

    public function __construct(
        private readonly StockService $stock,
    ) {}

    public function template(string $format = 'xlsx'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opening-stock-template-').'.'.$format;
        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($path);

        $headers = ['sku', 'quantity', 'unit_cost', 'serials', 'note'];
        $sample1 = ['SOFA-ROMA-GRY', '5', '120000.00', '', 'Opening showroom stock'];
        $sample2 = ['RECL-LAZ-BRN', '2', '95000.00', 'SN-RECL-001, SN-RECL-002', 'Serialized recliners'];

        $writer->addRow(Row::fromValues($headers));
        $writer->addRow(Row::fromValues($sample1));
        $writer->addRow(Row::fromValues($sample2));
        $writer->close();

        return $path;
    }

    public function uploadAndDryRun(UploadedFile $file, Location $location, User $user): OpeningStockImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('opening-stock-imports', Str::uuid().'.'.$extension, self::DISK);
        $absolute = Storage::disk(self::DISK)->path($path);

        try {
            $parsedRows = $this->parseFile($absolute, $extension);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw new RuntimeException($e instanceof RuntimeException ? $e->getMessage() : 'That file could not be read. Save it as .xlsx or UTF-8 .csv and try again.');
        }

        return DB::transaction(function () use ($file, $path, $absolute, $location, $user, $parsedRows) {
            $import = OpeningStockImport::query()->create([
                'location_id' => $location->id,
                'user_id' => $user->id,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_path' => $path,
                'file_hash' => hash_file('sha256', $absolute),
                'status' => ImportStatus::Checking,
                'total_rows' => count($parsedRows),
            ]);

            $skus = array_values(array_unique(array_filter(array_map(fn ($r) => $r['sku'], $parsedRows))));
            $variantsBySku = ProductVariant::query()
                ->with('product')
                ->whereIn('sku', $skus)
                ->get()
                ->keyBy(fn (ProductVariant $v) => strtoupper($v->sku));

            $seenSkus = [];
            $seenSerials = [];
            $allCandidateSerials = [];

            foreach ($parsedRows as $r) {
                foreach ($r['serials'] as $s) {
                    $allCandidateSerials[] = $s;
                }
            }

            // Re-runnable: an item that already has opening stock here is refused, never counted twice.
            $alreadyOpened = StockMovement::query()->where('location_id', $location->id)->where('type', MovementType::Opening->value)
                ->whereIn('product_variant_id', $variantsBySku->pluck('id'))->pluck('product_variant_id')->flip()->all();

            $existingSerials = $allCandidateSerials !== []
                ? StockUnit::query()->whereIn('serial_number', array_unique($allCandidateSerials))->pluck('serial_number')->flip()->all()
                : [];

            $createCount = 0;
            $failCount = 0;

            foreach ($parsedRows as $row) {
                $reasons = [];
                $sku = $row['sku'];
                $upperSku = strtoupper($sku);
                $variant = $upperSku !== '' ? $variantsBySku->get($upperSku) : null;

                if ($sku === '') {
                    $reasons[] = 'SKU is required.';
                } elseif (! $variant) {
                    $reasons[] = "Unknown SKU \"{$sku}\".";
                } elseif ($variant->product?->type === ProductType::Service) {
                    $reasons[] = "SKU \"{$sku}\" is a service item and cannot have stock.";
                }

                if ($variant && isset($alreadyOpened[$variant->id])) {
                    $reasons[] = "SKU \"{$sku}\" already has opening stock at {$location->code} (an earlier import or entry). Use a stock count or adjustment to change it.";
                }

                if ($upperSku !== '') {
                    if (isset($seenSkus[$upperSku])) {
                        $reasons[] = "SKU \"{$sku}\" appears more than once in this file (first on row {$seenSkus[$upperSku]}).";
                    } else {
                        $seenSkus[$upperSku] = $row['row_number'];
                    }
                }

                $qtyMilli = null;
                if ($row['quantity_raw'] === '') {
                    $reasons[] = 'Quantity is required.';
                } else {
                    try {
                        $qtyMilli = Quantity::parse($row['quantity_raw']);
                        if ($qtyMilli <= 0) {
                            $reasons[] = 'Quantity must be greater than zero.';
                        }
                    } catch (Throwable) {
                        $reasons[] = "Invalid quantity \"{$row['quantity_raw']}\".";
                    }
                }

                $costCents = null;
                if ($row['unit_cost_raw'] === '') {
                    $reasons[] = 'Unit cost is required.';
                } else {
                    try {
                        $costCents = Money::fromDecimal($row['unit_cost_raw'])->cents;
                        if ($costCents < 0) {
                            $reasons[] = 'Unit cost cannot be negative.';
                        }
                    } catch (Throwable) {
                        $reasons[] = "Invalid unit cost \"{$row['unit_cost_raw']}\".";
                    }
                }

                $serials = $row['serials'];
                if ($variant && $variant->product?->is_serialized && $qtyMilli !== null && $qtyMilli > 0) {
                    if ($qtyMilli % Quantity::SCALE !== 0) {
                        $reasons[] = 'Serialized items must have whole-number quantities.';
                    } else {
                        $expectedUnits = intdiv($qtyMilli, Quantity::SCALE);
                        if (count($serials) !== $expectedUnits) {
                            $reasons[] = "Serialized product requires {$expectedUnits} serial numbers (found ".count($serials).').';
                        }
                    }
                } elseif ($variant && ! $variant->product?->is_serialized && $serials !== []) {
                    $reasons[] = "SKU \"{$sku}\" is not a serialized product; leave serials blank.";
                }

                foreach ($serials as $serial) {
                    if (isset($existingSerials[$serial])) {
                        $reasons[] = "Serial number \"{$serial}\" already exists in stock.";
                    }
                    if (isset($seenSerials[$serial])) {
                        $reasons[] = "Serial number \"{$serial}\" is duplicated in this file (row {$seenSerials[$serial]}).";
                    } else {
                        $seenSerials[$serial] = $row['row_number'];
                    }
                }

                $action = $reasons === [] ? OpeningStockImportRow::CREATE : OpeningStockImportRow::FAIL;
                $action === OpeningStockImportRow::CREATE ? $createCount++ : $failCount++;

                OpeningStockImportRow::query()->create([
                    'opening_stock_import_id' => $import->id,
                    'row_number' => $row['row_number'],
                    'sku' => $sku ?: null,
                    'product_variant_id' => $variant?->id,
                    'quantity' => $qtyMilli,
                    'unit_cost' => $costCents,
                    'serials' => $serials ?: null,
                    'action' => $action,
                    'reasons' => $reasons ?: null,
                    'raw' => [
                        'sku' => $sku,
                        'quantity' => $row['quantity_raw'],
                        'unit_cost' => $row['unit_cost_raw'],
                        'serials' => implode(', ', $serials),
                        'note' => $row['note'],
                    ],
                ]);
            }

            $import->update([
                'status' => ImportStatus::Checked,
                'counts' => ['create' => $createCount, 'fail' => $failCount],
                'dry_run_at' => now(),
            ]);

            return $import->load(['rows.variant.product', 'location']);
        });
    }

    /**
     * Every row that isn't a plain "create": failed rows and their reasons (CSV lines).
     *
     * @return \Generator<int, array<int, string>>
     */
    public function reportRows(OpeningStockImport $import): \Generator
    {
        yield ['line', 'status', 'why', 'sku', 'quantity', 'unit_cost', 'serials', 'note'];

        foreach ($import->rows()->orderBy('row_number')->get() as $row) {
            if ($row->action === OpeningStockImportRow::CREATE && ($row->result ?? 'created') === 'created') {
                continue;
            }

            yield [(string) $row->row_number, $row->result ?? $row->action, implode(' ', $row->reasons ?? []), ...array_map('strval', array_values(array_intersect_key($row->raw ?? [], array_flip(['sku', 'quantity', 'unit_cost', 'serials', 'note']))))];
        }
    }

    public function commit(OpeningStockImport $import, User $actor): OpeningStockImport
    {
        if ($import->status !== ImportStatus::Checked) {
            throw new RuntimeException('Run the dry run first.');
        }

        return DB::transaction(function () use ($import, $actor) {
            $import = OpeningStockImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            if ($import->status === ImportStatus::Committed) {
                throw new RuntimeException('This opening stock import has already been committed.');
            }

            $rows = $import->rows()->with('variant.product')->orderBy('row_number')->get();
            $created = 0;
            $failed = 0;
            $entries = [];
            $businessDate = BusinessDay::current($import->location_id);

            foreach ($rows as $row) {
                if ($row->action !== OpeningStockImportRow::CREATE || ! $row->product_variant_id) {
                    $row->update(['result' => 'failed']);
                    $failed++;

                    continue;
                }

                $serials = $row->serials ?? [];

                if ($serials !== []) {
                    // Check none of the serials were created since dry run
                    if (StockUnit::query()->whereIn('serial_number', $serials)->exists()) {
                        $row->update([
                            'result' => 'failed',
                            'reasons' => ['One or more serial numbers were added since the dry run.'],
                        ]);
                        $failed++;

                        continue;
                    }

                    foreach ($serials as $serial) {
                        $unit = StockUnit::query()->create([
                            'serial_number' => $serial,
                            'product_variant_id' => $row->product_variant_id,
                            'location_id' => $import->location_id,
                            'status' => StockUnitStatus::InStock,
                            'condition' => StockUnitCondition::New,
                            'unit_cost' => $row->unit_cost ?? 0,
                            'received_at' => now(),
                            'note' => $row->raw['note'] ?? "Opening stock import #{$import->id}",
                        ]);

                        $entries[] = [
                            'product_variant_id' => $row->product_variant_id,
                            'location_id' => $import->location_id,
                            'type' => MovementType::Opening,
                            'quantity' => Quantity::SCALE,
                            'unit_cost' => $row->unit_cost ?? 0,
                            'reference' => $import,
                            'stock_unit_id' => $unit->id,
                            'user_id' => $actor->id,
                            'business_date' => $businessDate,
                            'note' => $row->raw['note'] ?? "Opening stock ({$serial})",
                        ];
                    }
                } else {
                    $entries[] = [
                        'product_variant_id' => $row->product_variant_id,
                        'location_id' => $import->location_id,
                        'type' => MovementType::Opening,
                        'quantity' => $row->quantity,
                        'unit_cost' => $row->unit_cost ?? 0,
                        'reference' => $import,
                        'user_id' => $actor->id,
                        'business_date' => $businessDate,
                        'note' => $row->raw['note'] ?? "Opening stock import #{$import->id}",
                    ];
                }

                $row->update(['result' => 'created']);
                $created++;
            }

            if ($entries !== []) {
                $this->stock->moveMany($entries);
            }

            $counts = ['created' => $created, 'failed' => $failed];
            $import->update([
                'status' => ImportStatus::Committed,
                'counts' => $counts,
                'committed_at' => now(),
            ]);

            AuditLog::record(
                'stock.opening_import_committed',
                $import,
                "Opening stock imported at {$import->location->name}: {$created} rows committed, {$failed} failed",
                null,
                $counts,
                $actor,
            );

            return $import->fresh(['rows.variant.product', 'location']);
        });
    }

    /**
     * @return array<int, array{row_number: int, sku: string, quantity_raw: string, unit_cost_raw: string, serials: array<int, string>, note: string|null}>
     */
    private function parseFile(string $path, string $extension): array
    {
        $reader = $this->reader($path, $extension);
        $reader->open($path);

        $rows = [];
        $headerMap = null;
        $rowNum = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNum++;
                    $cells = $row->toArray();

                    if ($rowNum === 1) {
                        $headerMap = $this->mapHeaders($cells);

                        continue;
                    }

                    if (! array_filter($cells, fn ($c) => $c !== null && trim((string) (is_scalar($c) ? $c : '')) !== '')) {
                        continue;
                    }

                    $sku = trim((string) ($cells[$headerMap['sku']] ?? ''));
                    $qtyRaw = trim((string) ($cells[$headerMap['quantity']] ?? ''));
                    $costRaw = trim((string) ($cells[$headerMap['unit_cost']] ?? ''));
                    $serialsRaw = isset($headerMap['serials']) ? trim((string) ($cells[$headerMap['serials']] ?? '')) : '';
                    $note = isset($headerMap['note']) ? (trim((string) ($cells[$headerMap['note']] ?? '')) ?: null) : null;

                    $serials = $serialsRaw !== ''
                        ? array_values(array_filter(array_map('trim', preg_split('/[,;\r\n]+/', $serialsRaw) ?: [])))
                        : [];

                    $rows[] = [
                        'row_number' => $rowNum,
                        'sku' => $sku,
                        'quantity_raw' => $qtyRaw,
                        'unit_cost_raw' => $costRaw,
                        'serials' => $serials,
                        'note' => $note,
                    ];
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($headerMap === null) {
            throw new RuntimeException('The file is empty.');
        }

        if ($rows === []) {
            throw new RuntimeException('The file has a header row but no data rows.');
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<string, int>
     */
    private function mapHeaders(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim((string) (is_scalar($cell) ? $cell : ''))) ?? '');
            $normalized = trim($normalized, '_');

            match ($normalized) {
                'sku', 'variant_sku', 'item_code' => $map['sku'] = $i,
                'quantity', 'qty', 'on_hand', 'opening_qty' => $map['quantity'] = $i,
                'unit_cost', 'cost', 'cost_price' => $map['unit_cost'] = $i,
                'serial', 'serials', 'serial_numbers' => $map['serials'] = $i,
                'note', 'notes', 'remark', 'remarks' => $map['note'] = $i,
                default => null,
            };
        }

        foreach (['sku', 'quantity', 'unit_cost'] as $required) {
            if (! array_key_exists($required, $map)) {
                throw new RuntimeException("Missing required column \"{$required}\" in the header row.");
            }
        }

        return $map;
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
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        return new CsvReader($options);
    }
}
