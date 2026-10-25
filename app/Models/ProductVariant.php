<?php

namespace App\Models;

use App\Support\Barcode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What is actually sold and stocked: one combination of a product's attribute values, with
 * its own SKU, barcode and prices (cents). A blank barcode gets a generated Code 128 one.
 * Every change to price, min_price or online_price is written to price_change_logs.
 * Cost columns (average_cost, last_cost) are only ever sent to people with the view-cost gate.
 */
#[Fillable(['product_id', 'sku', 'barcode', 'name', 'combination_key', 'price', 'min_price', 'online_price', 'reorder_level', 'reorder_qty', 'online_buffer', 'sort_order', 'is_active'])]
class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    /** Price fields whose changes are logged. */
    public const PRICE_FIELDS = ['price', 'min_price', 'online_price'];

    protected $attributes = [
        'is_active' => true,
        'name' => '',
        'combination_key' => '',
        'min_price' => 0,
        'average_cost' => 0,
        'reorder_level' => 0,
        'reorder_qty' => 0,
        'online_buffer' => 0,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'min_price' => 'integer',
            'online_price' => 'integer',
            'average_cost' => 'integer',
            'last_cost' => 'integer',
            'reorder_level' => 'integer',
            'reorder_qty' => 'integer',
            'online_buffer' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $variant) {
            if (blank($variant->barcode)) {
                $variant->barcode = Barcode::generate();
            }
        });

        static::created(function (self $variant) {
            foreach (self::PRICE_FIELDS as $field) {
                if ($variant->{$field} !== null) {
                    PriceChangeLog::write($variant, $field, null, $variant->{$field});
                }
            }
        });

        static::updated(function (self $variant) {
            foreach (array_intersect(self::PRICE_FIELDS, array_keys($variant->getChanges())) as $field) {
                PriceChangeLog::write($variant, $field, $variant->getOriginal($field), $variant->{$field});
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_value');
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_supplier')->withPivot(['supplier_sku', 'last_cost', 'is_preferred'])->withTimestamps();
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(PriceChangeLog::class)->latest('id');
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function isDefault(): bool
    {
        return $this->combination_key === '';
    }

    public function effectiveOnlinePrice(): int
    {
        return $this->online_price ?? $this->price;
    }

    /** Selling at this price (cents) needs a manager. */
    public function belowMinimum(int $price): bool
    {
        return $price < $this->min_price;
    }

    /**
     * Sorted value ids as the unique key of a combination: [17, 4] → "4-17", [] → "".
     *
     * @param  array<int, int>  $valueIds
     */
    public static function combinationKey(array $valueIds): string
    {
        $ids = array_map('intval', $valueIds);
        sort($ids);

        return implode('-', $ids);
    }
}
