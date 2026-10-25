<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

/**
 * Builds wordpress-plugin/mvrk-woo-connector into storage/app/private/woo-plugin/
 * mvrk-woo-connector-<version>.zip (+ .sha256), with the brand credit taken from config/brand.php.
 * Downloadable from the Online store page.
 */
#[Signature('woo:plugin-package')]
#[Description('Build the versioned WordPress plugin zip')]
class WooPluginPackage extends Command
{
    public const SOURCE = 'wordpress-plugin/mvrk-woo-connector';

    public function handle(): int
    {
        $path = self::build();
        $this->info("Built {$path}");
        $this->line('sha256: '.trim((string) file_get_contents("{$path}.sha256")));

        return self::SUCCESS;
    }

    public static function version(): string
    {
        $header = (string) file_get_contents(base_path(self::SOURCE.'/mvrk-woo-connector.php'));
        if (! preg_match('/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', $header, $m)) {
            throw new RuntimeException('No Version: header in the plugin file.');
        }

        return $m[1];
    }

    public static function zipPath(): string
    {
        return storage_path('app/private/woo-plugin/mvrk-woo-connector-'.self::version().'.zip');
    }

    public static function build(): string
    {
        $source = base_path(self::SOURCE);
        $zipPath = self::zipPath();
        @mkdir(dirname($zipPath), 0775, true);

        $brand = sprintf("<?php\n/*\n * Brand credit, written by `php artisan woo:plugin-package` from the ERP's config/brand.php.\n */\ndefined('ABSPATH') || exit;\n\nconst MVRK_WC_BRAND = [\n    'name' => %s,\n    'website' => %s,\n    'credit' => %s,\n];\n",
            var_export((string) config('brand.name'), true), var_export((string) config('brand.website'), true), config('brand.credit') ? 'true' : 'false');

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$zipPath}");
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = 'mvrk-woo-connector/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($source))), '/');
            if (str_contains($relative, '/tests/') || str_ends_with($relative, '.DS_Store')) {
                continue;
            }
            $relative === 'mvrk-woo-connector/includes/brand.php'
                ? $zip->addFromString($relative, $brand)
                : $zip->addFile($file->getPathname(), $relative);
        }
        $zip->close();

        file_put_contents("{$zipPath}.sha256", hash_file('sha256', $zipPath)."\n");

        return $zipPath;
    }
}
