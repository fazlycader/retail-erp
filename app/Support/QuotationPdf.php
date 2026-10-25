<?php

namespace App\Support;

use App\Models\CompanySetting;
use App\Models\Quotation;

/**
 * Generates an A4 PDF document for a Quotation using built-in Type1 Helvetica fonts.
 */
final class QuotationPdf
{
    public static function render(Quotation $quotation): string
    {
        $quotation->loadMissing(['customer', 'location', 'salesperson', 'lines']);
        $company = CompanySetting::current();

        $ops = [];

        // Header bar
        $ops[] = '0.09 0.11 0.18 rg';
        $ops[] = '40 765 515 45 re f';
        $ops[] = '1 1 1 rg';
        $ops[] = self::text(52, 788, $company->name ?: 'Retail ERP', 14, true);
        $ops[] = self::text(420, 788, 'QUOTATION', 13, true);
        $ops[] = self::text(420, 773, $quotation->number, 10, false);

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

        // Customer & Quote details boxes
        $ops[] = '0.95 0.96 0.98 rg';
        $ops[] = '40 645 250 72 re f';
        $ops[] = '305 645 250 72 re f';
        $ops[] = '0.1 0.1 0.1 rg';

        $ops[] = self::text(50, 702, 'CUSTOMER', 8, true);
        $ops[] = self::text(50, 688, (string) ($quotation->customer?->name ?: 'Walk-in Customer'), 10, true);
        if ($quotation->customer?->phone) {
            $ops[] = self::text(50, 675, 'Tel: '.($quotation->customer->displayPhone() ?? $quotation->customer->phone), 8);
        }
        if ($quotation->customer?->email) {
            $ops[] = self::text(50, 663, $quotation->customer->email, 8);
        }

        $ops[] = self::text(315, 702, 'QUOTATION DETAILS', 8, true);
        $ops[] = self::text(315, 688, 'Location: '.($quotation->location?->name ?? ''), 9, true);
        $ops[] = self::text(315, 675, 'Date: '.($quotation->created_at?->format('Y-m-d') ?? now()->toDateString()), 8);
        $ops[] = self::text(315, 663, 'Valid Until: '.($quotation->valid_until?->format('Y-m-d') ?? ''), 8);
        if ($quotation->salesperson) {
            $ops[] = self::text(315, 651, 'Prepared by: '.$quotation->salesperson->name, 8);
        }

        // Table Header
        $tableY = 618;
        $ops[] = '0.2 0.24 0.32 rg';
        $ops[] = "40 {$tableY} 515 20 re f";
        $ops[] = '1 1 1 rg';
        $ops[] = self::text(46, $tableY + 6, 'SKU / Item', 8, true);
        $ops[] = self::text(290, $tableY + 6, 'Qty', 8, true);
        $ops[] = self::text(340, $tableY + 6, 'Unit Price', 8, true);
        $ops[] = self::text(415, $tableY + 6, 'Discount', 8, true);
        $ops[] = self::text(480, $tableY + 6, 'Line Total', 8, true);

        $ops[] = '0.1 0.1 0.1 rg';
        $rowY = $tableY - 16;

        foreach ($quotation->lines as $line) {
            if ($rowY < 130) {
                break;
            }
            $label = trim(($line->sku_snapshot ? $line->sku_snapshot.' — ' : '').$line->name_snapshot);
            $ops[] = self::text(46, $rowY, mb_substr($label, 0, 44), 8, true);
            $ops[] = self::text(290, $rowY, Quantity::format($line->quantity), 8);
            $ops[] = self::text(340, $rowY, Money::fromCents($line->unit_price)->format(), 8);
            $ops[] = self::text(415, $rowY, $line->discount_cents > 0 ? Money::fromCents($line->discount_cents)->format() : '-', 8);
            $ops[] = self::text(480, $rowY, Money::fromCents($line->line_total)->format(), 8, true);

            $ops[] = '0.88 0.88 0.88 RG 0.5 w';
            $lineUnder = $rowY - 5;
            $ops[] = "40 {$lineUnder} m 555 {$lineUnder} l S";
            $rowY -= 18;
        }

        $totY = max(80, $rowY - 12);
        $ops[] = self::text(370, $totY, 'Subtotal:', 9);
        $ops[] = self::text(465, $totY, Money::fromCents($quotation->subtotal)->format(), 9);
        $totY -= 14;

        if ($quotation->order_discount_cents > 0) {
            $ops[] = self::text(370, $totY, 'Order Discount:', 9);
            $ops[] = self::text(465, $totY, '-'.Money::fromCents($quotation->order_discount_cents)->format(), 9);
            $totY -= 14;
        }

        if ($quotation->tax_total > 0) {
            $taxLabel = $quotation->prices_include_tax ? 'Included VAT:' : 'VAT:';
            $ops[] = self::text(370, $totY, $taxLabel, 9);
            $ops[] = self::text(465, $totY, Money::fromCents($quotation->tax_total)->format(), 9);
            $totY -= 14;
        }

        if ($quotation->delivery_charge > 0) {
            $ops[] = self::text(370, $totY, 'Delivery / Service:', 9);
            $ops[] = self::text(465, $totY, Money::fromCents($quotation->delivery_charge)->format(), 9);
            $totY -= 14;
        }

        $ops[] = self::text(370, $totY, 'GRAND TOTAL:', 10, true);
        $ops[] = self::text(465, $totY, Money::fromCents($quotation->grand_total)->format(), 10, true);

        if ($quotation->notes) {
            $ops[] = self::text(40, $totY - 24, 'Notes: '.mb_substr(preg_replace('/\s+/', ' ', $quotation->notes), 0, 95), 8);
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
