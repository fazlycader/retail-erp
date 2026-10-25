<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One spreadsheet row: what the dry run decided (create / update / unchanged / fail + reasons) and what the commit did. */
class CatalogImportRow extends Model
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const UNCHANGED = 'unchanged';

    public const FAIL = 'fail';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'reasons' => 'array',
            'data' => 'array',
            'row_number' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CatalogImport::class, 'catalog_import_id');
    }
}
