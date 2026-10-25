<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExportReport;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\ReportExport;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRange;
use App\Services\Reports\ReportScope;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reports: landing page, each report (location, range presets, cards, charts, tables), CSV/XLSX
 * exports (large ones in the background) and a print / Save-as-PDF view.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $scope = ReportScope::resolve($request);

        return Inertia::render('Admin/Reports/Index', [
            'groups' => ReportCatalog::forUser($request->user()),
            'today' => BusinessDay::current($scope['ids'][0] ?? 0),
            'exports' => $this->exports($request),
        ]);
    }

    public function show(Request $request, string $report, ReportBuilder $reports): Response
    {
        [$range, $meta, $error, $scope] = $this->resolve($request, $report);

        return Inertia::render('Admin/Reports/Show', [
            'report' => ['key' => $report, ...$meta],
            'range' => $range->toArray(),
            'preset' => $request->query('preset'),
            'presets' => ReportRange::PRESETS,
            'today' => BusinessDay::current($range->locationIds[0] ?? 0),
            'locations' => ReportScope::options($request->user()),
            'location' => $scope['selected'],
            'days' => $report === 'dead_stock' ? $this->days($request) : null,
            'error' => $error,
            'data' => $error ? null : $reports->build($report, $range, $request->user(), ['days' => $this->days($request)]),
        ]);
    }

    public function print(Request $request, string $report, ReportBuilder $reports): View
    {
        [$range, $meta, $error, $scope] = $this->resolve($request, $report);
        abort_if($error, 422, $error);

        return view('reports.print', [
            'meta' => $meta,
            'range' => $range,
            'data' => $reports->build($report, $range, $request->user(), ['days' => $this->days($request)]),
            'company' => CompanySetting::current()->name,
            'locations' => Location::query()->whereIn('id', $range->locationIds)->orderBy('id')->pluck('name')->implode(', '),
        ]);
    }

    public function export(Request $request, string $report, ReportBuilder $reports, ReportExporter $exporter): BinaryFileResponse|JsonResponse
    {
        [$range, $meta, $error] = $this->resolve($request, $report);
        abort_if($error, 422, $error);

        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])], 'table' => ['nullable', 'string', 'max:40']]);
        $data = $reports->build($report, $range, $request->user(), ['days' => $this->days($request)]);
        $table = collect($data['tables'])->firstWhere('key', $format['table'] ?? null) ?? $data['tables'][0];

        if (count($table['rows']) > ReportExporter::SYNC_LIMIT) {
            $export = new ReportExport;
            $export->forceFill([
                'user_id' => $request->user()->id,
                'report' => $report,
                'format' => $format['format'],
                'filters' => ['from' => $range->from, 'to' => $range->to, 'table' => $table['key'], 'locations' => $range->locationIds, 'days' => $this->days($request)],
                'status' => 'queued',
            ])->save();
            ExportReport::dispatch($export->id);

            return response()->json(['queued' => true, 'message' => 'This export is large, so it is being prepared in the background. It appears under Downloads on the Reports page in a minute or two.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'rpt');
        $exporter->write($table, $format['format'], $path, ['title' => "{$meta['title']} · {$table['title']}", 'period' => "{$range->from} to {$range->to}"]);

        return response()->download($path, ReportExporter::filename($report, $range, $table['key'], $format['format']))->deleteFileAfterSend();
    }

    public function download(Request $request, ReportExport $export): BinaryFileResponse
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless($export->status === 'ready' && $export->path && Storage::disk('local')->exists($export->path), 404);

        return response()->download(Storage::disk('local')->path($export->path), basename($export->path));
    }

    public function exportsStatus(Request $request): JsonResponse
    {
        return response()->json(['exports' => $this->exports($request)]);
    }

    /**
     * @return array{0: ReportRange, 1: array<string, mixed>, 2: ?string, 3: array{ids: array<int, int>, selected: string}}
     */
    private function resolve(Request $request, string $report): array
    {
        abort_unless(ReportCatalog::exists($report), 404);
        abort_unless(ReportCatalog::canView($request->user(), $report), 403);

        $meta = ReportCatalog::all()[$report];
        $scope = ReportScope::resolve($request);
        abort_if($scope['ids'] === [], 403);

        $data = $request->validate([
            'preset' => ['nullable', Rule::in(ReportRange::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (! empty($data['from'])) {
            $from = $data['from'];
            $to = $data['to'] ?? $from;
            $range = $from <= $to ? new ReportRange($scope['ids'], $from, $to) : new ReportRange($scope['ids'], $to, $from);
        } else {
            $range = ReportRange::preset($scope['ids'], $data['preset'] ?? 'today');
        }

        $error = $range->days() > $meta['max_days'] ? "This report covers at most {$meta['max_days']} days. Choose a shorter range." : null;

        return [$range, $meta, $error, $scope];
    }

    private function days(Request $request): int
    {
        return max(7, min(730, $request->integer('days', 90) ?: 90));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exports(Request $request): array
    {
        return ReportExport::query()->where('user_id', $request->user()->id)->latest('id')->limit(10)->get()
            ->map(fn (ReportExport $e) => [
                'id' => $e->id,
                'title' => (ReportCatalog::all()[$e->report]['title'] ?? $e->report).' · '.$e->filters['from'].($e->filters['to'] !== $e->filters['from'] ? ' → '.$e->filters['to'] : ''),
                'format' => strtoupper($e->format),
                'status' => $e->status,
                'rows' => $e->rows,
                'error' => $e->error,
                'url' => $e->status === 'ready' ? route('reports.exports.download', $e) : null,
                'created_at' => $e->created_at->toIso8601String(),
            ])->all();
    }
}
