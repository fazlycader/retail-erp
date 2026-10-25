<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number', 'from_location_id', 'to_location_id', 'status', 'vehicle_note', 'note',
    'requested_by', 'requested_at', 'dispatched_by', 'dispatched_at',
    'received_by', 'received_at', 'closed_by', 'closed_at', 'resolution', 'resolution_note',
    'sync_version',
])]
class Transfer extends Model
{
    protected $attributes = [
        'status' => 'draft',
        'sync_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'requested_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'closed_at' => 'datetime',
            'sync_version' => 'integer',
        ];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
