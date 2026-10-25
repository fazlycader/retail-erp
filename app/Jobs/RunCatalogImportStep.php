<?php

namespace App\Jobs;

use App\Models\CatalogImport;
use App\Services\Catalog\Import\ImportService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * One piece of a catalog import: "stage" (read the file into rows), or a "check" / "commit"
 * chunk of row ids. Runs as the person who started the import (price logs and audit rows).
 */
class RunCatalogImportStep implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $rowIds
     */
    public function __construct(public int $importId, public string $step, public array $rowIds = []) {}

    public function handle(ImportService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $import = CatalogImport::query()->with('user')->findOrFail($this->importId);

        if ($import->user) {
            Auth::guard('web')->setUser($import->user);
        }

        match ($this->step) {
            'stage' => $service->stage($import),
            'check' => $service->checkRows($import, $this->rowIds),
            'commit' => $service->commitRows($import, $this->rowIds),
        };
    }

    public function failed(?\Throwable $e): void
    {
        app(ImportService::class)->markFailed(CatalogImport::query()->find($this->importId), $e);
    }
}
