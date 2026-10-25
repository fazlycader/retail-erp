<?php

namespace App\Console\Commands;

use App\Services\Woo\WooLinker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Before the first push: match products already in WooCommerce to ours by SKU (never creates
 * duplicates). Run with --dry-run first and read the unmatched lists.
 */
#[Signature('woo:link {--dry-run : Report only, change nothing}')]
#[Description('Link existing WooCommerce products to ERP variants by SKU')]
class WooLink extends Command
{
    public function handle(WooLinker $linker): int
    {
        $r = $linker->run((bool) $this->option('dry-run'));
        Cache::forever('woo:last-link-report', [...$r, 'at' => now()->toIso8601String(), 'dry_run' => (bool) $this->option('dry-run')]);

        $this->info(($this->option('dry-run') ? 'DRY RUN: would link ' : 'Linked ').count($r['linked'])." variant(s); {$r['already']} product(s) were already linked.");
        foreach (['woo_only' => 'In WooCommerce but not in the ERP (left alone)', 'local_only' => 'Online in the ERP but not in WooCommerce (the next push creates them)', 'conflicts' => 'Fix these first'] as $key => $title) {
            $this->newLine();
            $this->line("{$title}: ".count($r[$key]));
            foreach (array_slice($r[$key], 0, 50) as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }
}
