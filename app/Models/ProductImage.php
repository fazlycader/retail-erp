<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** WebP photo + thumbnail (ImageUploader), optionally for one variant. */
#[Fillable(['product_id', 'product_variant_id', 'image_path', 'thumbnail_path', 'alt', 'sort_order'])]
class ProductImage extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }

    public function thumbnailUrl(): string
    {
        return Storage::disk('public')->url($this->thumbnail_path);
    }

    /**
     * @return array{id: int, url: string, thumbnail_url: string, alt: ?string, variant_id: ?int}
     */
    public function toArrayForPage(): array
    {
        return ['id' => $this->id, 'url' => $this->url(), 'thumbnail_url' => $this->thumbnailUrl(), 'alt' => $this->alt, 'variant_id' => $this->product_variant_id];
    }
}
