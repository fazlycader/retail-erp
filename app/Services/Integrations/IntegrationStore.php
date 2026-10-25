<?php

namespace App\Services\Integrations;

use App\Models\IntegrationCredential;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The config-resolution layer for integration credentials: the database (integration_credentials) is the ONLY source. .env is not
 * consulted at runtime; `integrations:migrate-from-env` copied it across once.
 *
 * All values are loaded with one query and kept for the rest of the request. Queue workers live for hours, so the cache is also
 * dropped before every job (see AppServiceProvider), and on every write here: an Owner's edit is used by the very next job.
 */
class IntegrationStore
{
    /** @var array<string, ?string>|null key_name => decrypted value */
    private ?array $values = null;

    public function get(string $key): ?string
    {
        $definition = $this->definition($key);
        $value = $this->all()[$key] ?? null;

        if (blank($value) && isset($definition['fallback'])) {
            return $this->get($definition['fallback']);
        }

        return blank($value) ? null : $value;
    }

    /** An on/off credential (type boolean). */
    public function enabled(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOLEAN);
    }

    /** Every one of these has a value. */
    public function filled(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->get($key) === null) {
                return false;
            }
        }

        return true;
    }

    /** Replace a value, keeping the current one as the one-step-back undo. */
    public function set(string $key, ?string $value, ?User $by): IntegrationCredential
    {
        $definition = $this->definition($key);
        $value = $value === null || trim($value) === '' ? null : trim($value);

        $credential = DB::transaction(function () use ($key, $definition, $value, $by) {
            $row = IntegrationCredential::where('key_name', $key)->lockForUpdate()->first() ?? $this->newRow($key, $definition);

            $row->fill([
                'previous_value' => $row->value,
                'previous_changed_at' => $row->exists ? $row->updated_at : null,
                'value' => $value,
                'updated_by' => $by?->id,
            ])->save();

            return $row;
        });

        $this->flush();
        Log::info('integrations.updated', ['key' => $key, 'by' => $by?->id, 'now_set' => $value !== null]);   // never the value

        return $credential;
    }

    /** Put the previous value back. The value being replaced becomes the new "previous", so an undo can itself be undone. */
    public function undo(string $key, ?User $by): IntegrationCredential
    {
        $this->definition($key);

        $credential = DB::transaction(function () use ($key, $by) {
            $row = IntegrationCredential::where('key_name', $key)->lockForUpdate()->firstOrFail();
            abort_if($row->previous_changed_at === null && $row->previous_value === null, 422, 'There is no earlier value to go back to.');

            $row->fill([
                'value' => $row->previous_value,
                'previous_value' => $row->value,
                'previous_changed_at' => $row->updated_at,
                'updated_by' => $by?->id,
            ])->save();

            return $row;
        });

        $this->flush();
        Log::info('integrations.undone', ['key' => $key, 'by' => $by?->id]);

        return $credential;
    }

    public function flush(): void
    {
        $this->values = null;
    }

    /**
     * Create any registry rows that don't exist yet (value empty) and refresh every row's label, secrecy and guide from the
     * registry. Never touches a value. Run by the importer, so the screen always lists every known credential.
     */
    public function syncDefinitions(): void
    {
        foreach (CredentialRegistry::CREDENTIALS as $key => $definition) {
            $row = IntegrationCredential::firstOrNew(['key_name' => $key]);
            $row->fill(['provider' => $definition['provider'], 'description' => $definition['description'], 'is_secret' => $definition['secret'], 'guide' => $definition['guide']]);
            $row->save();
        }

        $this->flush();
    }

    /** @return array<string, ?string> */
    private function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            return $this->values = IntegrationCredential::query()->get(['key_name', 'value'])->mapWithKeys(fn ($r) => [$r->key_name => $r->value])->all();
        } catch (QueryException $e) {
            // The table doesn't exist yet (a deploy mid-migration): behave as "nothing configured" rather than crash, and say so.
            Log::error('integrations.unavailable', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function newRow(string $key, array $definition): IntegrationCredential
    {
        return new IntegrationCredential(['key_name' => $key, 'provider' => $definition['provider'], 'description' => $definition['description'], 'is_secret' => $definition['secret'], 'guide' => $definition['guide']]);
    }

    private function definition(string $key): array
    {
        return CredentialRegistry::definition($key)
            ?? throw new InvalidArgumentException("Unknown integration credential [{$key}]: declare it in CredentialRegistry first.");
    }
}
