<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('reports:prune-exports')]
#[Description('Delete background report exports older than 7 days')]
class PruneReportExports extends Command
{
    public function handle(): int
    {
        ReportExport::query()->where('created_at', '<', now()->subDays(7))->each(function (ReportExport $export) {
            if ($export->path) {
                Storage::disk('local')->delete($export->path);
            }
            $export->delete();
        });

        return self::SUCCESS;
    }
}
