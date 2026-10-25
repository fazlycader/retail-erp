<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Reserve to view in showroom" lead from the WordPress plugin. */
class StorefrontEnquiry extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['preferred_date' => 'date'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
