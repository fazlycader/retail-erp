<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRange;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** A large report export, run from the scheduler's queue:work; downloadable when ready. */
class ExportReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 50;

    public int $tries = 2;

    public function __construct(public int $exportId) {}

    public function handle(ReportBuilder $reports, ReportExporter $exporter): void
    {
        $export = ReportExport::query()->with('user')->find($this->exportId);

        if (! $export || $export->status === 'ready' || ! $export->user) {
            return;
        }

        $export->forceFill(['status' => 'running'])->save();
        $filters = $export->filters;
        $range = new ReportRange($filters['locations'], $filters['from'], $filters['to']);
        $result = $reports->build($export->report, $range, $export->user, ['days' => $filters['days'] ?? 90]);
        $table = collect($result['tables'])->firstWhere('key', $filters['table']) ?? $result['tables'][0];

        $path = 'exports/'.$export->id.'-'.ReportExporter::filename($export->report, $range, $table['key'], $export->format);
        Storage::disk('local')->makeDirectory('exports');
        $rows = $exporter->write($table, $export->format, Storage::disk('local')->path($path), [
            'title' => ReportCatalog::all()[$export->report]['title'].' · '.$table['title'],
            'period' => "{$range->from} to {$range->to}",
        ]);

        $export->forceFill(['status' => 'ready', 'path' => $path, 'rows' => $rows, 'finished_at' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        ReportExport::query()->whereKey($this->exportId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
