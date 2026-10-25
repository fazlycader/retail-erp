<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A material issued to a job or returned from it (quantity always positive, type says which). */
#[Fillable(['production_job_id', 'product_variant_id', 'location_id', 'type', 'quantity', 'unit_cost', 'cost', 'stock_movement_id', 'user_id', 'note', 'created_at'])]
class JobMaterial extends Model
{
    public const ISSUE = 'issue';

    public const RETURN = 'return';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost' => 'integer', 'cost' => 'integer', 'created_at' => 'datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProductionJob::class, 'production_job_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Signed: + issued, − returned. */
    public function signedCost(): int
    {
        return $this->type === self::RETURN ? -$this->cost : $this->cost;
    }

    public function signedQuantity(): int
    {
        return $this->type === self::RETURN ? -$this->quantity : $this->quantity;
    }
}
