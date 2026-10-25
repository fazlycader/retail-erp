<?php

namespace App\Support;

use App\Models\CompanySetting;
use App\Models\PurchaseOrder;

/**
 * Generates an A4 PDF document for a Purchase Order using built-in Type1 Helvetica fonts
 * (no external binary or network dependency).
 */
final class PurchaseOrderPdf
{
    public static function render(PurchaseOrder $po): string
    {
        return self::generate($po);
    }

    public static function generate(PurchaseOrder $po): string
    {
        $po->loadMissing(['supplier', 'location', 'lines.variant.product', 'creator']);
        $company = CompanySetting::current();

        $ops = [];

        // Header bar
        $ops[] = '0.09 0.11 0.18 rg';
        $ops[] = '40 765 515 45 re f';
        $ops[] = '1 1 1 rg';
        $ops[] = self::text(52, 788, $company->name ?: 'Retail ERP', 14, true);
        $ops[] = self::text(400, 788, 'PURCHASE ORDER', 13, true);
        $ops[] = self::text(400, 773, $po->number, 10, false);

        $ops[] = '0.15 0.15 0.15 rg';
        $y = 742;
        if ($company->address) {
            $ops[] = self::text(40, $y, $company->address, 9);
            $y -= 13;
        }
        $metaLine = array_filter([
            $company->phone ? 'Tel: '.$company->phone : null,
            $company->email ? 'Email: '.$company->email : null,
            $company->vat_registered && $company->vat_number ? 'VAT: '.$company->vat_number : null,
        ]);
        if ($metaLine !== []) {
            $ops[] = self::text(40, $y, implode('  |  ', $metaLine), 8);
        }

        // Supplier & Deliver-to boxes
        $ops[] = '0.95 0.96 0.98 rg';
        $ops[] = '40 645 250 72 re f';
        $ops[] = '305 645 250 72 re f';
        $ops[] = '0.1 0.1 0.1 rg';

        $ops[] = self::text(50, 702, 'SUPPLIER', 8, true);
        $ops[] = self::text(50, 688, (string) $po->supplier?->name, 10, true);
        if ($po->supplier?->contact_person) {
            $ops[] = self::text(50, 675, 'Attn: '.$po->supplier->contact_person, 8);
        }
        if ($po->supplier?->phone || $po->supplier?->email) {
            $ops[] = self::text(50, 663, trim(($po->supplier->phone ?? '').' '.($po->supplier->email ?? '')), 8);
        }

        $ops[] = self::text(315, 702, 'DELIVER TO', 8, true);
        $ops[] = self::text(315, 688, ($po->location?->code ? $po->location->code.' - ' : '').($po->location?->name ?? ''), 10, true);
        $ops[] = self::text(315, 675, 'Date: '.$po->created_at?->format('Y-m-d'), 8);
        if ($po->expected_date) {
            $ops[] = self::text(315, 663, 'Expected: '.$po->expected_date->format('Y-m-d'), 8);
        }
        $ops[] = self::text(315, 651, 'Status: '.strtoupper($po->status->label()), 8);

        // Table Header
        $tableY = 618;
        $ops[] = '0.2 0.24 0.32 rg';
        $ops[] = "40 {$tableY} 515 20 re f";
        $ops[] = '1 1 1 rg';
        $ops[] = self::text(46, $tableY + 6, 'SKU', 8, true);
        $ops[] = self::text(140, $tableY + 6, 'Item Description', 8, true);
        $ops[] = self::text(335, $tableY + 6, 'Qty', 8, true);
        $ops[] = self::text(385, $tableY + 6, 'Unit Cost', 8, true);
        $ops[] = self::text(475, $tableY + 6, 'Line Total', 8, true);

        $ops[] = '0.1 0.1 0.1 rg';
        $rowY = $tableY - 16;

        foreach ($po->lines as $line) {
            if ($rowY < 120) {
                break;
            }
            $desc = trim(($line->variant?->product?->name ?? '').' '.($line->variant?->name ?? ''));
            $ops[] = self::text(46, $rowY, mb_substr((string) $line->variant?->sku, 0, 18), 8, true);
            $ops[] = self::text(140, $rowY, mb_substr($desc, 0, 38), 8);
            $ops[] = self::text(335, $rowY, Quantity::format($line->ordered_qty), 8);
            $ops[] = self::text(385, $rowY, Money::fromCents($line->unit_cost)->format(), 8);
            $ops[] = self::text(475, $rowY, Money::fromCents($line->line_total)->format(), 8, true);

            $ops[] = '0.88 0.88 0.88 RG 0.5 w';
            $lineUnder = $rowY - 5;
            $ops[] = "40 {$lineUnder} m 555 {$lineUnder} l S";
            $rowY -= 18;
        }

        // Totals
        $totY = max(70, $rowY - 12);
        $ops[] = self::text(380, $totY, 'Subtotal:', 9);
        $ops[] = self::text(465, $totY, Money::fromCents($po->subtotal)->format(), 9);
        $totY -= 14;

        if ($po->discount_total > 0) {
            $ops[] = self::text(380, $totY, 'Discount:', 9);
            $ops[] = self::text(465, $totY, '-'.Money::fromCents($po->discount_total)->format(), 9);
            $totY -= 14;
        }

        if ($po->tax_total > 0) {
            $ops[] = self::text(380, $totY, 'Tax:', 9);
            $ops[] = self::text(465, $totY, Money::fromCents($po->tax_total)->format(), 9);
            $totY -= 14;
        }

        $totalCents = $po->total_amount ?: $po->total;
        $ops[] = self::text(380, $totY, 'TOTAL:', 10, true);
        $ops[] = self::text(465, $totY, Money::fromCents($totalCents)->format(), 10, true);

        $noteText = $po->notes ?: $po->note;
        if ($noteText) {
            $ops[] = self::text(40, $totY - 24, 'Note: '.mb_substr(preg_replace('/\s+/', ' ', $noteText), 0, 95), 8);
        }

        return self::compilePdf(implode("\n", $ops));
    }

    private static function text(int $x, int $y, string $text, int $size = 9, bool $bold = false): string
    {
        $font = $bold ? '/F2' : '/F1';
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);

        return "BT {$font} {$size} Tf {$x} {$y} Td ({$safe}) Tj ET";
    }

    private static function compilePdf(string $stream): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $len = strlen($stream);
        $objects[6] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }
}
