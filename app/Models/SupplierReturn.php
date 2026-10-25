<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierReturn extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected $attributes = [
        'credit_expected' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'returned_at' => 'date',
            'credit_expected' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
