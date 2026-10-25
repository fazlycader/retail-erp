<?php

namespace App\Services\Production;

use App\Models\CustomSpec;
use App\Models\QuotationLine;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Support\ImageUploader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Custom specs: written in the quote/order line editor (a new line gets its spec before the
 * document is saved; saving attaches it), copied when a quote becomes an order, and shown to the
 * workshop on the job. Files (sketches, photos, the signed quote) live on the private disk.
 */
class CustomSpecService
{
    public const DIR = 'custom-specs';

    public const MAX_ATTACHMENTS = 12;

    public function __construct(private ImageUploader $images) {}

    /** @param  array<string, mixed>  $data */
    public function save(?CustomSpec $spec, array $data, ?User $actor = null): CustomSpec
    {
        $spec ??= new CustomSpec(['created_by' => $actor?->id]);
        $spec->fill(array_intersect_key($data, array_flip([
            'base_product_id', 'width_mm', 'depth_mm', 'height_mm', 'material', 'fabric_code', 'colour_code', 'finish', 'notes', 'lead_time_days',
        ])))->save();
        $this->syncSummary($spec);

        return $spec;
    }

    /** Backwards-compatible name used by the original workshop editor. */
    public function recordApproval(CustomSpec $spec, string $approvedBy, string $date, ?UploadedFile $signedQuote, User $actor): CustomSpec
    {
        return $this->approve($spec, $approvedBy, $date, $signedQuote, $actor);
    }

    public function addAttachment(CustomSpec $spec, UploadedFile $file): CustomSpec
    {
        $files = $spec->attachments ?? [];
        if (count($files) >= self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('A spec holds at most '.self::MAX_ATTACHMENTS.' files.');
        }

        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $stored = $this->images->store($file, self::DIR, 'local');
            $files[] = ['path' => $stored['image_path'], 'thumb' => $stored['thumbnail_path'], 'name' => $file->getClientOriginalName()];
        } else {
            $files[] = ['path' => $file->storeAs(self::DIR, Str::uuid().'.'.$file->extension(), 'local'), 'name' => $file->getClientOriginalName()];
        }
        $spec->update(['attachments' => array_values($files)]);

        return $spec;
    }

    public function removeAttachment(CustomSpec $spec, int $index): CustomSpec
    {
        $files = $spec->attachments ?? [];
        if (! isset($files[$index])) {
            return $spec;
        }

        $this->deleteIfUnused(array_filter([$files[$index]['path'] ?? null, $files[$index]['thumb'] ?? null]), $spec->id);
        unset($files[$index]);
        $spec->update(['attachments' => array_values($files)]);

        return $spec;
    }

    /** The customer approved the design: who, when, and the signed quote (photo or PDF). */
    public function approve(CustomSpec $spec, string $approvedBy, string $date, ?UploadedFile $signedQuote, User $actor): CustomSpec
    {
        $path = $spec->signed_quote_path;
        if ($signedQuote) {
            $path = $signedQuote->storeAs(self::DIR.'/approvals', Str::uuid().'.'.$signedQuote->extension(), 'local');
        }
        $spec->update(['approved_by_name' => trim($approvedBy), 'approved_on' => $date, 'signed_quote_path' => $path, 'approval_recorded_by' => $actor->id]);

        return $spec;
    }

    /**
     * Saving a quote or order (re)creates its lines: point the spec at the new line. A spec already
     * on another document is copied instead of moved.
     */
    public function attach(?int $specId, QuotationLine|SalesOrderLine $line): void
    {
        if (! $specId || ! ($spec = CustomSpec::query()->find($specId))) {
            return;
        }

        $parent = fn (?Model $l) => $l instanceof SalesOrderLine ? 'order:'.$l->sales_order_id : ($l instanceof QuotationLine ? 'quote:'.$l->quotation_id : null);
        $current = $spec->specable_id ? $spec->specable : null;
        if ($current && $parent($current) !== $parent($line)) {
            $spec = $this->copy($spec);
        }

        $spec->specable()->associate($line)->save();
        $line->forceFill(['custom_spec_id' => $spec->id])->save();
        $this->syncSummary($spec);
    }

    /** A copy with its own files (quote → order), so editing one never changes the other. */
    public function copy(CustomSpec $spec): CustomSpec
    {
        $disk = Storage::disk('local');
        $copyFile = function (?string $path) use ($disk) {
            if (! $path || ! $disk->exists($path)) {
                return null;
            }
            $new = dirname($path).'/'.Str::uuid().'.'.pathinfo($path, PATHINFO_EXTENSION);
            $disk->copy($path, $new);

            return $new;
        };

        $copy = $spec->replicate(['specable_type', 'specable_id']);
        $copy->attachments = collect($spec->attachments ?? [])->map(fn (array $a) => array_filter([
            'path' => $copyFile($a['path'] ?? null), 'thumb' => $copyFile($a['thumb'] ?? null), 'name' => $a['name'] ?? null,
        ]))->filter(fn (array $a) => isset($a['path']))->values()->all();
        $copy->signed_quote_path = $copyFile($spec->signed_quote_path);
        $copy->save();

        return $copy;
    }

    /** Documents and lists print the line's custom_spec text: keep it in step with the spec. */
    public function syncSummary(CustomSpec $spec): void
    {
        $line = $spec->specable_id ? $spec->specable : null;
        if ($line instanceof QuotationLine || $line instanceof SalesOrderLine) {
            $line->forceFill(['custom_spec' => mb_substr($spec->summary(), 0, 1000) ?: null])->save();
        }
    }

    /** @param  array<int, string>  $paths */
    private function deleteIfUnused(array $paths, int $exceptSpecId): void
    {
        foreach ($paths as $path) {
            $used = CustomSpec::query()->where('id', '!=', $exceptSpecId)->where('attachments', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $path).'%')->exists();
            if (! $used) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
