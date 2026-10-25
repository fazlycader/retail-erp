<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\GoLive\CustomerImporter;
use App\Services\GoLive\Importer;
use App\Services\GoLive\ImportRow;
use App\Services\GoLive\OpenOrderImporter;
use App\Services\GoLive\SupplierBalanceImporter;
use App\Services\GoLive\SupplierImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use RuntimeException;

/**
 * Go-live data (docs/DATA-IMPORT.md). Dry run by default; --commit writes. Every run writes a CSV
 * report of each row that is not plain (skipped, failed or created with a note) to
 * storage/app/private/go-live/. Running the same file again skips what is already in.
 *
 *   php artisan golive:import customers customers.xlsx --user=owner@example.lk
 *   php artisan golive:import customers customers.xlsx --user=owner@example.lk --commit
 *   php artisan golive:import --template=open-orders
 */
#[Signature('golive:import {kind? : suppliers | customers | open-orders | supplier-balances} {file? : .xlsx or .csv} {--user= : Admin email that the records are created by} {--commit : Write the data (without it: dry run)} {--template= : Write an empty template for this kind and stop}')]
#[Description('Import go-live data (dry run by default)')]
class GoLiveImport extends Command
{
    private const KINDS = [
        'suppliers' => SupplierImporter::class,
        'customers' => CustomerImporter::class,
        'open-orders' => OpenOrderImporter::class,
        'supplier-balances' => SupplierBalanceImporter::class,
    ];

    public function handle(): int
    {
        if ($template = $this->option('template')) {
            return $this->template($template);
        }

        $kind = (string) $this->argument('kind');
        $file = (string) $this->argument('file');

        if (! isset(self::KINDS[$kind]) || $file === '') {
            $this->error('Usage: golive:import {'.implode('|', array_keys(self::KINDS)).'} {file} --user=admin@… [--commit]');

            return self::FAILURE;
        }

        $actor = User::query()->where('email', strtolower((string) $this->option('user')))->where('role', Role::Admin->value)->where('is_active', true)->first();
        if (! $actor) {
            $this->error('Give --user= the email of an active admin (the records are created in their name).');

            return self::FAILURE;
        }

        /** @var Importer $importer */
        $importer = app(self::KINDS[$kind]);
        $commit = (bool) $this->option('commit');

        try {
            $result = $importer->run($file, $actor, $commit);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $c = $result['counts'];
        $this->info(($commit ? 'IMPORTED' : 'DRY RUN (nothing written)').": {$c['rows']} rows · {$c[ImportRow::CREATE]} ".($commit ? 'created' : 'to create')." · {$c[ImportRow::SKIP]} skipped · {$c[ImportRow::FAIL]} failed");

        foreach (array_slice(array_filter($result['rows'], fn (ImportRow $r) => ! $r->isPlain()), 0, 15) as $row) {
            $this->line('  line '.implode(',', $row->lines)." [{$row->status}] {$row->message()}");
        }

        $this->line("Report (every row that isn't plain): {$result['report']}");

        if (! $commit && $c[ImportRow::FAIL] === 0) {
            $this->line('Looks good. Run again with --commit to import.');
        }

        return $c[ImportRow::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function template(string $kind): int
    {
        if (! isset(self::KINDS[$kind])) {
            $this->error('Kinds: '.implode(', ', array_keys(self::KINDS)));

            return self::FAILURE;
        }

        /** @var Importer $importer */
        $importer = app(self::KINDS[$kind]);
        $dir = storage_path('app/private/go-live');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = "{$dir}/template-{$kind}.csv";
        $writer = new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([...$importer->required(), ...$importer->optional()]));
        $writer->close();

        $this->info("Template: {$path} (required: ".implode(', ', $importer->required()).')');

        return self::SUCCESS;
    }
}
