<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Every customer SMS: queued, sent, failed or skipped (SMS off or not set up), with why. */
#[Fillable(['location_id', 'customer_id', 'to_phone', 'template', 'body', 'status', 'error', 'provider_ref', 'related_type', 'related_id', 'dedupe_key', 'sent_at'])]
class SmsMessage extends Model
{
    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
