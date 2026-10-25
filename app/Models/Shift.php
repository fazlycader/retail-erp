<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Models\Concerns\BelongsToLocation;
use App\Models\Concerns\TracksSyncVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use BelongsToLocation, TracksSyncVersion;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
            'opening_float' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'variance' => 'integer',
            'opening_counts' => 'array',
            'closing_counts' => 'array',
            'terminal_totals' => 'array',
            'z_report' => 'array',
            'sync_version' => 'integer',
        ];
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ShiftStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    public function driverCollections(): HasMany
    {
        return $this->hasMany(DriverCollection::class);
    }
}
