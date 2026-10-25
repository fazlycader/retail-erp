<?php

namespace App\Models;

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'location_id', 'scope', 'category_id', 'blind', 'status', 'business_date', 'note', 'started_by', 'started_at', 'reviewed_by', 'reviewed_at', 'posted_by', 'posted_at'])]
class StockCount extends Model
{
    use BelongsToLocation;

    protected $attributes = [
        'scope' => 'full',
        'blind' => false,
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'scope' => StockCountScope::class,
            'blind' => 'boolean',
            'status' => StockCountStatus::class,
            'business_date' => 'date',
            'started_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
