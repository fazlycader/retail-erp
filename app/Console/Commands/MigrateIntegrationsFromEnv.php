<?php

namespace App\Console\Commands;

use App\Services\Integrations\CredentialRegistry;
use App\Services\Integrations\EnvImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One-time move of integration credentials (WOO_*) from .env into the database (admin-editable at
 * Settings → Integrations). Idempotent: a value already in the database is never overwritten.
 */
#[Signature('integrations:migrate-from-env')]
#[Description('Copy integration credentials (WooCommerce, …) from .env into the database, without overwriting any already there')]
class MigrateIntegrationsFromEnv extends Command
{
    public function handle(EnvImporter $importer): int
    {
        $report = $importer->import();

        $this->line('Integration credentials: the database is now what the app reads. The .env entries are left in place as a backup.');
        $this->listSection('Migrated from .env now', $report['migrated']);
        $this->listSection('Already in the database (left untouched)', $report['already']);
        $this->listSection('NOT found in .env (still to be filled in at Integrations)', $report['missing']);

        return self::SUCCESS;
    }

    private function listSection(string $title, array $keys): void
    {
        $this->newLine();
        $this->info($title.': '.count($keys));

        foreach ($keys as $key) {
            $this->line("  - {$key}  (".CredentialRegistry::PROVIDERS[CredentialRegistry::CREDENTIALS[$key]['provider']]['label'].')');
        }
    }
}
