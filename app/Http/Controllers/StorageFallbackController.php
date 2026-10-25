<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves public-disk files (product photos, category images, logos) when the web server can't follow the
 * public/storage symlink (some shared hosts). Normally the web server serves them directly and
 * this never runs. File names are random UUIDs, so they're cached for a year.
 */
class StorageFallbackController extends Controller
{
    private const TYPES = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'gif' => 'image/gif'];

    public function __invoke(string $path): BinaryFileResponse
    {
        abort_if(str_contains($path, '..'), 404);

        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $file = realpath($disk->path($path));
        $extension = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));

        abort_unless($file && $root && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) && isset(self::TYPES[$extension]), 404);

        return response()->file($file, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // SVGs can carry scripts: never let them run as a page.
            ...($extension === 'svg' ? ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"] : []),
        ]);
    }
}
