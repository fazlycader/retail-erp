<?php

namespace App\Services\Integrations;

use App\Models\IntegrationCredential;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/**
 * Copies integration credentials from the old .env variables into integration_credentials, once.
 *
 * Reads the .env FILE itself (plus real environment variables, which win), not the env helper: on a server with cached config Laravel never
 * loads .env, so that helper would see nothing. Safe to re-run: a row that already has a value is NEVER overwritten (the database is the
 * source of truth from the first run on), so a later run can only fill a gap.
 */
class EnvImporter
{
    public function __construct(private readonly IntegrationStore $store) {}

    /**
     * @param  array<string, string>|null  $source  variable => value; null = read the environment + the .env file
     * @return array{migrated: list<string>, already: list<string>, missing: list<string>}
     */
    public function import(?array $source = null): array
    {
        $source ??= $this->readEnvironment();
        $this->store->syncDefinitions();
        $report = ['migrated' => [], 'already' => [], 'missing' => []];

        DB::transaction(function () use ($source, &$report) {
            foreach (CredentialRegistry::keys() as $key) {
                $row = IntegrationCredential::where('key_name', $key)->lockForUpdate()->firstOrFail();
                $value = isset($source[$key]) ? trim((string) $source[$key]) : '';

                if ($row->isSet()) {
                    $report['already'][] = $key;
                } elseif ($value !== '' && strtolower($value) !== 'null') {
                    $row->forceFill(['value' => $value, 'updated_by' => null])->save();   // updated_by null = "imported from .env"
                    $report['migrated'][] = $key;
                } else {
                    $report['missing'][] = $key;
                }
            }
        });

        $this->store->flush();

        return $report;
    }

    /** @return array<string, string> */
    private function readEnvironment(): array
    {
        $file = app()->environmentFilePath();
        $values = is_readable($file) ? Dotenv::parse((string) file_get_contents($file)) : [];

        foreach (CredentialRegistry::keys() as $key) {
            $real = Env::getRepository()->get($key);
            if (is_string($real) && $real !== '') {
                $values[$key] = $real;
            }
        }

        return array_map(fn ($v) => (string) $v, array_filter($values, fn ($v) => $v !== null));
    }
}
