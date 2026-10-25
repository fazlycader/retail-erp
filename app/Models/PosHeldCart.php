<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A parked POS cart (client-side cart JSON) to resume later at the same location. */
#[Fillable(['location_id', 'user_id', 'label', 'cart'])]
class PosHeldCart extends Model
{
    use BelongsToLocation;

    protected function casts(): array
    {
        return ['cart' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
