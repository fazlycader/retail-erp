<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One uploaded catalog spreadsheet and its dry run / commit (App\Services\Catalog\ImportService). */
class CatalogImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'counts' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'dry_run_at' => 'datetime',
            'committed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CatalogImportRow::class);
    }

    public function option(string $key): bool
    {
        return (bool) ($this->options[$key] ?? false);
    }
}
