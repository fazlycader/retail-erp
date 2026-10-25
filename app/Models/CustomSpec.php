<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What the customer asked for on a custom line (quotation or sales order line): base product,
 * dimensions, material, fabric and colour codes, finish, notes, sketches and photos, the customer's
 * approval (with the signed quote) and the lead time. Files live on the private disk.
 */
#[Fillable([
    'specable_type', 'specable_id', 'base_product_id', 'width_mm', 'depth_mm', 'height_mm', 'material',
    'fabric_code', 'colour_code', 'finish', 'notes', 'attachments', 'approved_by_name', 'approved_on',
    'signed_quote_path', 'approval_recorded_by', 'lead_time_days', 'created_by',
])]
class CustomSpec extends Model
{
    protected function casts(): array
    {
        return [
            'width_mm' => 'integer',
            'depth_mm' => 'integer',
            'height_mm' => 'integer',
            'attachments' => 'array',
            'approved_on' => 'date',
            'lead_time_days' => 'integer',
        ];
    }

    public function specable(): MorphTo
    {
        return $this->morphTo();
    }

    public function baseProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'base_product_id')->withTrashed();
    }

    public function approvalRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_recorded_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_on !== null;
    }

    /** One line for documents and lists: "W 2100 × D 900 × H 850 mm · Teak · Fabric VL-204 …". */
    public function summary(): string
    {
        $size = collect(['W' => $this->width_mm, 'D' => $this->depth_mm, 'H' => $this->height_mm])
            ->filter()->map(fn ($v, $k) => "{$k} {$v}")->implode(' × ');

        return collect([
            $size !== '' ? "{$size} mm" : null,
            $this->material,
            $this->fabric_code ? "Fabric {$this->fabric_code}" : null,
            $this->colour_code ? "Colour {$this->colour_code}" : null,
            $this->finish ? "Finish {$this->finish}" : null,
            $this->notes,
        ])->filter(fn ($v) => filled($v))->implode(' · ');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'base_product_id' => $this->base_product_id,
            'base_product' => $this->base_product_id ? $this->baseProduct?->name : null,
            'width_mm' => $this->width_mm,
            'depth_mm' => $this->depth_mm,
            'height_mm' => $this->height_mm,
            'material' => $this->material,
            'fabric_code' => $this->fabric_code,
            'colour_code' => $this->colour_code,
            'finish' => $this->finish,
            'notes' => $this->notes,
            'attachments' => collect($this->attachments ?? [])->values()->map(fn (array $a, int $i) => [
                'index' => $i,
                'name' => $a['name'] ?? 'file',
                'url' => route('private-files.show', ['path' => $a['path']]),
                'thumb' => isset($a['thumb']) ? route('private-files.show', ['path' => $a['thumb']]) : null,
            ])->all(),
            'approved_by_name' => $this->approved_by_name,
            'approved_on' => $this->approved_on?->toDateString(),
            'signed_quote_url' => $this->signed_quote_path ? route('private-files.show', ['path' => $this->signed_quote_path]) : null,
            'lead_time_days' => $this->lead_time_days,
            'summary' => $this->summary(),
        ];
    }
}
