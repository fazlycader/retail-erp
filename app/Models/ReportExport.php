<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A large report export prepared in the background (ExportReport job), downloadable when ready. */
class ReportExport extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'finished_at' => 'datetime', 'rows' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
