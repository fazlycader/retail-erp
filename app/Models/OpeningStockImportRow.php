<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['opening_stock_import_id', 'row_number', 'sku', 'product_variant_id', 'quantity', 'unit_cost', 'serials', 'action', 'reasons', 'result', 'raw'])]
class OpeningStockImportRow extends Model
{
    public const CREATE = 'create';

    public const FAIL = 'fail';

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'serials' => 'array',
            'reasons' => 'array',
            'raw' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(OpeningStockImport::class, 'opening_stock_import_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
