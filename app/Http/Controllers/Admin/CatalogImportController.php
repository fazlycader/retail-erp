<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogImport;
use App\Models\CatalogImportRow;
use App\Services\Catalog\Import\ImportFields;
use App\Services\Catalog\Import\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogImportController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('edit-catalog');

        return Inertia::render('Admin/Products/Import', [
            'import' => null,
            'rows' => null,
            'rowFilter' => 'all',
            'recentImports' => self::recentImports(),
            'fields' => ImportFields::all(),
            'targets' => ImportFields::targets(),
            'maxKilobytes' => (int) config('catalog.import.max_kilobytes'),
            'maxRows' => (int) config('catalog.import.max_rows'),
        ]);
    }

    public function template(string $format, ImportService $service): BinaryFileResponse
    {
        Gate::authorize('edit-catalog');
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        $path = $service->template($format);

        return response()->download($path, "catalog-import-template.{$format}")->deleteFileAfterSend(true);
    }

    public function upload(Request $request, ImportService $service): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:'.config('catalog.import.max_kilobytes')],
        ], [
            'file.extensions' => 'Upload an Excel (.xlsx) or CSV (.csv) file.',
        ]);

        try {
            $import = $service->upload($request->file('file'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('products.import.show', $import);
    }

    public function show(Request $request, CatalogImport $import): Response
    {
        Gate::authorize('edit-catalog');

        $filter = $request->validate([
            'action' => ['nullable', Rule::in(['all', 'fail', 'create', 'update', 'unchanged'])],
        ])['action'] ?? 'all';

        $rows = $import->rows()
            ->when($filter !== 'all', fn ($q) => $q->where('action', $filter))
            ->orderByRaw("CASE WHEN action = 'fail' THEN 0 WHEN action = 'create' THEN 1 WHEN action = 'update' THEN 2 ELSE 3 END")
            ->orderBy('row_number')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (CatalogImportRow $row) => [
                'id' => $row->id,
                'row_number' => $row->row_number,
                'sku' => $row->sku,
                'barcode' => $row->barcode,
                'product_key' => $row->product_key,
                'action' => $row->action,
                'result' => $row->result,
                'reasons' => $row->reasons ?? [],
                'changes' => $row->data['changes'] ?? [],
                'product' => $row->data['product'] ?? [],
                'variant' => $row->data['variant'] ?? [],
            ]);

        return Inertia::render('Admin/Products/Import', [
            'import' => [
                'id' => $import->id,
                'original_name' => $import->original_name,
                'status' => $import->status->value,
                'status_label' => $import->status->label(),
                'running' => $import->status->running(),
                'headers' => $import->headers,
                'mapping' => $import->mapping,
                'create_missing' => $import->option('create_missing'),
                'total_rows' => $import->total_rows,
                'processed_rows' => $import->processed_rows,
                'counts' => $import->counts,
                'error' => $import->error,
                'dry_run_at' => $import->dry_run_at?->format('j M Y, g:i a'),
                'committed_at' => $import->committed_at?->format('j M Y, g:i a'),
            ],
            'rows' => $rows,
            'rowFilter' => $filter,
            'recentImports' => self::recentImports(),
            'fields' => ImportFields::all(),
            'targets' => ImportFields::targets(),
            'maxKilobytes' => (int) config('catalog.import.max_kilobytes'),
            'maxRows' => (int) config('catalog.import.max_rows'),
        ]);
    }

    public function dryRun(Request $request, CatalogImport $import, ImportService $service): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
            'create_missing' => ['boolean'],
        ]);

        try {
            $service->start($import, $data['mapping'], (bool) ($data['create_missing'] ?? false));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('products.import.show', $import);
    }

    public function commit(CatalogImport $import, ImportService $service): RedirectResponse
    {
        Gate::authorize('edit-catalog');

        try {
            $service->commit($import);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('products.import.show', $import)->with('success', 'Import started.');
    }

    public function report(CatalogImport $import, ImportService $service): StreamedResponse
    {
        Gate::authorize('edit-catalog');

        return response()->streamDownload(function () use ($import, $service) {
            $out = fopen('php://output', 'w');

            foreach ($service->reportRows($import) as $row) {
                fputcsv($out, array_map(fn ($cell) => ImportService::csvText((string) $cell), $row));
            }

            fclose($out);
        }, "catalog-import-{$import->id}-report.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function recentImports(): array
    {
        return CatalogImport::query()
            ->with('user:id,name')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (CatalogImport $i) => [
                'id' => $i->id,
                'original_name' => $i->original_name,
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'total_rows' => $i->total_rows,
                'counts' => $i->counts,
                'user' => $i->user?->name,
                'created_at' => $i->created_at?->format('j M Y, g:i a'),
            ])->values()->all();
    }
}
