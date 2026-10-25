<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Resizes uploads with GD (available on Hostinger shared hosting) and stores a WebP
 * image plus a small WebP thumbnail on the public disk.
 */
class ImageUploader
{
    public const MAX_SIZE = 1200;

    public const THUMBNAIL_SIZE = 400;

    public const QUALITY = 80;

    public const LOGO_SIZE = 512;

    /** Upload limit in KB for photos (5 MB; big enough for phone photos, small enough for GD). */
    public const MAX_KB = 5120;

    /**
     * Validation rules for photo uploads. The size limit is also capped by php.ini.
     *
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.UploadLimit::kilobytes(self::MAX_KB), 'dimensions:max_width=6000,max_height=6000'];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field = 'image'): array
    {
        $limit = UploadLimit::describe(UploadLimit::kilobytes(self::MAX_KB) * 1024);

        return [
            "{$field}.max" => "The photo must be smaller than {$limit}.",
            "{$field}.uploaded" => "The photo could not be uploaded. Please use an image smaller than {$limit}.",
        ];
    }

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(Driver::class);
    }

    /**
     * Customer files and job photos go on the private 'local' disk (served by PrivateFileController).
     *
     * @return array{image_path: string, thumbnail_path: string}
     */
    public function store(UploadedFile $file, string $directory, string $disk = 'public'): array
    {
        $name = trim($directory, '/').'/'.Str::uuid();
        $image = $this->manager->decodePath($file->getRealPath());
        $disk = Storage::disk($disk);

        $disk->put("{$name}.webp", (string) $image->scaleDown(self::MAX_SIZE, self::MAX_SIZE)->encode(new WebpEncoder(self::QUALITY)));
        $disk->put("{$name}_thumb.webp", (string) $image->scaleDown(self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE)->encode(new WebpEncoder(self::QUALITY)));

        return ['image_path' => "{$name}.webp", 'thumbnail_path' => "{$name}_thumb.webp"];
    }

    /**
     * Company logo: at most 512px, saved as PNG to keep transparency (also readable by dompdf).
     */
    public function storeLogo(UploadedFile $file): string
    {
        $path = 'logos/'.Str::uuid().'.png';
        $image = $this->manager->decodePath($file->getRealPath())->scaleDown(self::LOGO_SIZE, self::LOGO_SIZE);

        Storage::disk('public')->put($path, (string) $image->encode(new PngEncoder));

        return $path;
    }

    /**
     * Copy an existing image pair (e.g. when duplicating an item) so each record owns its files.
     *
     * @return array{image_path: ?string, thumbnail_path: ?string}
     */
    public function copy(?string $imagePath, ?string $thumbnailPath, string $directory): array
    {
        $disk = Storage::disk('public');

        if (! $imagePath || ! $disk->exists($imagePath)) {
            return ['image_path' => null, 'thumbnail_path' => null];
        }

        $name = trim($directory, '/').'/'.Str::uuid();
        $disk->copy($imagePath, "{$name}.webp");

        if ($thumbnailPath && $disk->exists($thumbnailPath)) {
            $disk->copy($thumbnailPath, "{$name}_thumb.webp");
        }

        return [
            'image_path' => "{$name}.webp",
            'thumbnail_path' => $thumbnailPath ? "{$name}_thumb.webp" : null,
        ];
    }

    public function delete(?string ...$paths): void
    {
        $paths = array_filter($paths);

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }
}
