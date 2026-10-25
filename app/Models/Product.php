<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A catalog product (shared by every location). Always has at least one variant: a product
 * without options has exactly one default variant (combination_key ''). Save through
 * App\Services\Catalog\ProductService so variants, attributes and the price log stay consistent.
 */
#[Fillable([
    'category_id', 'name', 'slug', 'brand', 'description', 'type', 'unit', 'width_mm', 'depth_mm', 'height_mm', 'weight_g',
    'assembly_required', 'requires_delivery', 'warranty_months', 'is_serialized', 'publish_online', 'seo_title',
    'seo_description', 'is_active', 'tags',
])]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'type' => 'stock',
        'unit' => 'pcs',
        'is_active' => true,
        'publish_online' => false,
        'assembly_required' => false,
        'requires_delivery' => false,
        'is_serialized' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'assembly_required' => 'boolean',
            'requires_delivery' => 'boolean',
            'is_serialized' => 'boolean',
            'publish_online' => 'boolean',
            'is_active' => 'boolean',
            'tags' => 'array',
            'width_mm' => 'integer',
            'depth_mm' => 'integer',
            'height_mm' => 'integer',
            'weight_g' => 'integer',
            'warranty_months' => 'integer',
        ];
    }

    /** Raw material (in a category flagged is_material, or below one): may move in fractions. */
    public function isMaterial(): bool
    {
        return in_array((int) $this->category_id, Category::materialIds(), true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The attributes its variants are made of, in order (Colour, then Size…). */
    public function variantAttributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class)->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Name, SKU or barcode. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', $like)->orWhere('barcode', $term)));
    }

    /** Own warranty, else the nearest category (or parent) default, else none. */
    public function effectiveWarrantyMonths(): ?int
    {
        if ($this->warranty_months !== null) {
            return $this->warranty_months;
        }

        $category = $this->category;

        for ($depth = 0; $category && $depth < Category::MAX_DEPTH; $depth++) {
            if ($category->default_warranty_months !== null) {
                return $category->default_warranty_months;
            }

            $category = $category->parent;
        }

        return null;
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;

        for ($i = 2; static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
