<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Every change of a variant's price, min_price or online_price: who, when, old → new, and
 * where it came from (form, bulk, import). Written by ProductVariant's model events, so no
 * code path can change a price without a log row. Never updated.
 */
class PriceChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    private static string $source = 'form';

    private static ?string $note = null;

    protected function casts(): array
    {
        return [
            'old_value' => 'integer',
            'new_value' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Run $callback with price changes recorded as coming from $source ("bulk", "import").
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function from(string $source, Closure $callback, ?string $note = null): mixed
    {
        [$previous, $previousNote] = [self::$source, self::$note];
        [self::$source, self::$note] = [$source, $note];

        try {
            return $callback();
        } finally {
            [self::$source, self::$note] = [$previous, $previousNote];
        }
    }

    public static function write(ProductVariant $variant, string $field, ?int $old, ?int $new): void
    {
        static::query()->create([
            'product_variant_id' => $variant->id,
            'user_id' => Auth::id(),
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'source' => self::$source,
            'note' => self::$note,
            'created_at' => now(),
        ]);
    }
}
