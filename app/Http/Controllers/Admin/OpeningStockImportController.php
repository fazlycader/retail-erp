<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\OpeningStockImport;
use App\Models\OpeningStockImportRow;
use App\Services\Inventory\OpeningStockImportService;
use App\Support\CurrentLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpeningStockImportController extends Controller
{
    public function index(Request $request, CurrentLocation $currentLocation): Response
    {
        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $imports = OpeningStockImport::query()
            ->forLocation()
            ->with(['location:id,name,code', 'user:id,name'])
            ->latest('id')
            ->limit(20)
            ->get();

        $activeImportId = $request->integer('import_id') ?: $imports->first()?->id;
        $activeImport = $activeImportId
            ? OpeningStockImport::query()->with(['location:id,name,code', 'user:id,name', 'rows.variant.product'])->find($activeImportId)
            : null;

        if ($activeImport && ! $user->worksAt($activeImport->location_id)) {
            $activeImport = null;
        }

        return Inertia::render('Admin/Stock/OpeningImport', [
            'locations' => $accessibleLocations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
            ])->values()->all(),
            'defaultLocationId' => $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'imports' => $imports->map(fn (OpeningStockImport $imp) => [
                'id' => $imp->id,
                'original_name' => $imp->original_name,
                'location_name' => $imp->location?->name,
                'location_code' => $imp->location?->code,
                'user_name' => $imp->user?->name,
                'status' => $imp->status->value,
                'total_rows' => $imp->total_rows,
                'counts' => $imp->counts,
                'created_at' => $imp->created_at?->toIso8601String(),
                'committed_at' => $imp->committed_at?->toIso8601String(),
            ])->values()->all(),
            'activeImport' => $activeImport ? [
                'id' => $activeImport->id,
                'original_name' => $activeImport->original_name,
                'location_id' => $activeImport->location_id,
                'location_name' => $activeImport->location?->name,
                'location_code' => $activeImport->location?->code,
                'status' => $activeImport->status->value,
                'total_rows' => $activeImport->total_rows,
                'counts' => $activeImport->counts,
                'error' => $activeImport->error,
                'dry_run_at' => $activeImport->dry_run_at?->toIso8601String(),
                'committed_at' => $activeImport->committed_at?->toIso8601String(),
                'rows' => $activeImport->rows->map(fn (OpeningStockImportRow $r) => [
                    'id' => $r->id,
                    'row_number' => $r->row_number,
                    'sku' => $r->sku,
                    'product_name' => $r->variant?->product?->name,
                    'variant_name' => $r->variant?->name,
                    'quantity' => $r->quantity,
                    'serials' => $r->serials ?? [],
                    'action' => $r->action,
                    'result' => $r->result,
                    'reasons' => $r->reasons ?? [],
                    ...($canViewCost ? ['unit_cost' => $r->unit_cost] : []),
                ])->values()->all(),
            ] : null,
            'canViewCost' => $canViewCost,
        ]);
    }

    public function template(string $format, OpeningStockImportService $service): BinaryFileResponse
    {
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        $path = $service->template($format);

        return response()->download($path, "opening-stock-template.{$format}")->deleteFileAfterSend();
    }

    public function upload(Request $request, OpeningStockImportService $service): RedirectResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:10240'],
        ]);

        $location = Location::query()->findOrFail((int) $validated['location_id']);

        if (! $request->user()->worksAt($location)) {
            abort(403);
        }

        try {
            $import = $service->uploadAndDryRun($request->file('file'), $location, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('stock.opening.index', ['import_id' => $import->id])
            ->with('success', 'Dry run complete. Review the rows below before committing.');
    }

    public function commit(Request $request, OpeningStockImport $import, OpeningStockImportService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($import->location_id)) {
            abort(403);
        }

        try {
            $service->commit($import, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock.opening.index', ['import_id' => $import->id])
            ->with('success', 'Opening stock committed.');
    }

    public function report(Request $request, OpeningStockImport $import, OpeningStockImportService $service): StreamedResponse
    {
        abort_unless($request->user()->worksAt($import->location_id), 403);

        return response()->streamDownload(function () use ($import, $service) {
            $out = fopen('php://output', 'w');
            foreach ($service->reportRows($import) as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, "opening-stock-{$import->id}-report.csv", ['Content-Type' => 'text/csv']);
    }
}
