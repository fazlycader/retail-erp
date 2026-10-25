<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['attribute_id', 'value', 'code', 'swatch_color', 'sort_order'])]
class AttributeValue extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $value) {
            $value->code = filled($value->code) ? self::cleanCode($value->code) : self::codeFor($value->value);
        });
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /** A typed code, uppercased, letters and digits only, at most 12 characters. */
    public static function cleanCode(string $code): string
    {
        return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($code))), 0, 12) ?: 'X';
    }

    /** A code derived from the value: "Dark grey" → "DAR", "3 seater" → "3SE". */
    public static function codeFor(string $value): string
    {
        return substr(self::cleanCode($value), 0, 3);
    }

    public function swatchImageUrl(): ?string
    {
        return $this->swatch_image_path ? Storage::disk('public')->url($this->swatch_image_path) : null;
    }

    /**
     * @return array{id: int, value: string, code: string, swatch_color: ?string, swatch_image_url: ?string}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
            'code' => $this->code,
            'swatch_color' => $this->swatch_color,
            'swatch_image_url' => $this->swatchImageUrl(),
        ];
    }
}
