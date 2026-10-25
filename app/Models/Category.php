<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Product categories, nested up to MAX_DEPTH levels (Living room › Sofas › Recliners).
 * Moves and depth checks go through App\Services\Catalog\CategoryTree.
 */
#[Fillable(['parent_id', 'name', 'slug', 'sort_order', 'is_active', 'is_material', 'default_warranty_months', 'commission_group'])]
class Category extends Model
{
    use HasFactory;

    public const MAX_DEPTH = 3;

    /** @var array<int, int>|null */
    private static ?array $materialIds = null;

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_material' => 'boolean',
            'sort_order' => 'integer',
            'default_warranty_months' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Ids of material categories: flagged ones and every category below them. Memoised per
     * request/process (forget() after changing categories in the same process).
     *
     * @return array<int, int>
     */
    public static function materialIds(): array
    {
        if (self::$materialIds !== null) {
            return self::$materialIds;
        }

        $parents = static::query()->pluck('parent_id', 'id')->all();
        $flagged = array_flip(static::query()->where('is_material', true)->pluck('id')->all());
        $ids = [];
        foreach (array_keys($parents) as $id) {
            for ($at = $id, $depth = 0; $at !== null && $depth <= self::MAX_DEPTH; $at = $parents[$at] ?? null, $depth++) {
                if (isset($flagged[$at])) {
                    $ids[] = (int) $id;
                    break;
                }
            }
        }

        return self::$materialIds = $ids;
    }

    public static function forget(): void
    {
        self::$materialIds = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }
}
