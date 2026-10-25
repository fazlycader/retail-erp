<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['location_id', 'user_id', 'original_name', 'file_path', 'file_hash', 'status', 'total_rows', 'business_date', 'counts', 'error', 'note', 'dry_run_at', 'committed_at'])]
class OpeningStockImport extends Model
{
    use BelongsToLocation;

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'business_date' => 'date',
            'counts' => 'array',
            'dry_run_at' => 'datetime',
            'committed_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(OpeningStockImportRow::class);
    }
}
