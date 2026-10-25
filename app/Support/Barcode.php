<?php

namespace App\Support;

use App\Models\ProductVariant;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Code 128 barcodes for variants. Blank barcodes get "20" + a 10-digit running number
 * (20xxxxxxxxxx, the in-store range, so it never clashes with a maker's EAN-13).
 */
final class Barcode
{
    public const PREFIX = '20';

    /** Printable ASCII only: what Code 128 (set B/C) encodes and scanners type back. */
    public const PATTERN = '/^[\x20-\x7E]{1,64}$/';

    public static function generate(): string
    {
        do {
            $code = self::PREFIX.str_pad((string) Counter::next('barcode'), 10, '0', STR_PAD_LEFT);
        } while (ProductVariant::withTrashed()->where('barcode', $code)->exists());

        return $code;
    }

    /** Inline SVG bars (no text), scaled to the box the label gives it. */
    public static function svg(string $code, float $width = 200, float $height = 40): string
    {
        $barcode = (new TypeCode128)->getBarcode($code);

        return (new SvgRenderer)->setSvgType(SvgRenderer::TYPE_SVG_INLINE)->render($barcode, $width, $height);
    }
}
