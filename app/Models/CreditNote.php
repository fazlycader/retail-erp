<?php

namespace App\Models;

use App\Enums\CreditNoteStatus;
use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    use BelongsToLocation;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'remaining' => 'integer',
            'expires_on' => 'date',
            'status' => CreditNoteStatus::class,
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }

    public function isUsable(): bool
    {
        return $this->status === CreditNoteStatus::Open && $this->remaining > 0 && ! $this->isExpired();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
