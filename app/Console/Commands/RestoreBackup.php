<?php

namespace App\Console\Commands;

use App\Support\SqlImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Restores a spatie/laravel-backup zip from the backups disk (S3-compatible bucket or local):
 * the database (replaces everything) and the uploaded images.
 *
 *   php artisan app:restore-backup --list
 *   php artisan app:restore-backup                       # newest backup
 *   php artisan app:restore-backup --file=retail-erp/2026-11-02-03-00-01.zip
 *   php artisan app:restore-backup --database-only | --files-only
 */
class RestoreBackup extends Command
{
    protected $signature = 'app:restore-backup {--file= : Backup zip on the backups disk} {--list : Show the available backups} {--database-only} {--files-only} {--force : Do not ask}';

    protected $description = 'Restore the database and uploaded images from a backup';

    public function handle(): int
    {
        $disk = Storage::disk('backups');
        $backups = collect($disk->allFiles(config('backup.backup.name')))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->sortByDesc(fn ($f) => $disk->lastModified($f))->values();

        if ($this->option('list') || $backups->isEmpty()) {
            $backups->isEmpty() ? $this->warn('No backups found on the "backups" disk.') : $this->table(['Backup', 'Size', 'Made'], $backups->map(fn ($f) => [
                $f, round($disk->size($f) / 1048576, 1).' MB', date('Y-m-d H:i', $disk->lastModified($f)),
            ])->all());

            return $backups->isEmpty() ? self::FAILURE : self::SUCCESS;
        }

        $file = $this->option('file') ?: $backups->first();

        if (! $disk->exists($file)) {
            $this->error("{$file} not found. Use --list.");

            return self::FAILURE;
        }

        $this->warn("Restoring {$file}. This REPLACES the current ".($this->option('files-only') ? 'images' : 'database'.($this->option('database-only') ? '' : ' and images')).'.');

        if (! $this->option('force') && ! $this->confirm('Continue?')) {
            return self::SUCCESS;
        }

        $work = storage_path('app/private/restore-'.date('YmdHis'));
        File::ensureDirectoryExists($work);
        $zipPath = "{$work}/backup.zip";

        $this->line('Downloading…');
        file_put_contents($zipPath, $disk->readStream($file));

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('The backup zip is damaged.');

            return self::FAILURE;
        }
        $zip->extractTo("{$work}/x");
        $zip->close();

        $this->call('down', ['--retry' => 30]);

        try {
            if (! $this->option('files-only')) {
                $dump = collect(File::allFiles("{$work}/x/db-dumps"))->first(fn ($f) => preg_match('/\.sql(\.gz)?$/', $f->getFilename()));

                if (! $dump) {
                    throw new \RuntimeException('No database dump in this backup.');
                }

                $this->line('Restoring the database…');
                $this->call('db:wipe', ['--force' => true]);
                $count = SqlImporter::import($dump->getPathname());
                $this->info("Database restored ({$count} statements).");
            }

            if (! $this->option('database-only') && is_dir("{$work}/x/public")) {
                $files = 0;
                foreach (File::allFiles("{$work}/x/public") as $image) {
                    Storage::disk('public')->put(str_replace('\\', '/', $image->getRelativePathname()), File::get($image->getPathname()));
                    $files++;
                }
                $this->info("Images restored ({$files} files).");
            }

            // Suppliers' invoice photos on GRNs (private disk).
            if (! $this->option('database-only') && is_dir("{$work}/x/private/grn-invoices")) {
                $files = 0;
                foreach (File::allFiles("{$work}/x/private/grn-invoices") as $invoice) {
                    Storage::disk('local')->put('grn-invoices/'.str_replace('\\', '/', $invoice->getRelativePathname()), File::get($invoice->getPathname()));
                    $files++;
                }
                $this->info("GRN invoices restored ({$files} files).");
            }

            $this->call('cache:clear');
        } finally {
            $this->call('up');
            File::deleteDirectory($work);
        }

        $this->info('Restore finished. Check the site, then run php artisan migrate --force if this backup is older than the code.');

        return self::SUCCESS;
    }
}
