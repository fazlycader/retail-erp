<?php

namespace App\Support;

use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\LocationSetting;
use App\Models\Payment;
use App\Models\SalesOrder;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * A4 sales documents (invoice, receipt, credit note, delivery note) rendered with dompdf from
 * resources/views/documents, plus the data the thermal receipt and the public digital copy share.
 */
final class SalesDocuments
{
    public static function invoicePdf(SalesOrder $order): string
    {
        return self::pdf('documents.invoice', self::invoiceData($order));
    }

    public static function receiptPdf(Payment $payment): string
    {
        return self::pdf('documents.receipt', self::receiptData($payment));
    }

    public static function creditNotePdf(CreditNote $note): string
    {
        return self::pdf('documents.credit-note', self::creditNoteData($note));
    }

    public static function deliveryNotePdf(SalesOrder $order): string
    {
        $order->loadMissing(['customer', 'location', 'lines']);

        return self::pdf('documents.delivery-note', [
            ...self::common($order->location, null),
            'title' => 'Delivery / collection note',
            'order' => $order,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function renderPdf(string $view, array $data): string
    {
        return self::pdf($view, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoiceData(SalesOrder $order): array
    {
        $order->loadMissing(['customer', 'location', 'salesperson:id,name', 'lines', 'payments' => fn ($q) => $q->counted()->with('method')]);

        return [
            ...self::common($order->location, route('documents.show', $order->shareToken())),
            'title' => self::invoiceTitle(),
            'order' => $order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function receiptData(Payment $payment): array
    {
        $payment->loadMissing(['customer', 'location', 'method', 'salesOrder', 'receivedBy:id,name', 'creditNote']);

        return [
            ...self::common($payment->location, route('documents.show', $payment->share_token)),
            'title' => 'Payment receipt',
            'payment' => $payment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function creditNoteData(CreditNote $note): array
    {
        $note->loadMissing(['customer', 'location', 'salesOrder', 'salesReturn.lines.orderLine', 'issuedBy:id,name']);

        return [
            ...self::common($note->location, route('documents.show', $note->share_token)),
            'title' => 'Credit note',
            'note' => $note,
        ];
    }

    /** "Tax Invoice" only when the company is VAT registered. */
    public static function invoiceTitle(): string
    {
        return CompanySetting::current()->vat_registered ? 'Tax Invoice' : 'Invoice';
    }

    public static function qr(string $url): string
    {
        return (new QRCode(new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 4,
            'addQuietzone' => true,
            'quietzoneSize' => 1,
        ])))->render($url);
    }

    /**
     * @return array<string, mixed>
     */
    private static function common(?Location $location, ?string $digitalUrl): array
    {
        $company = CompanySetting::current();

        return [
            'company' => $company,
            'logo' => self::logoDataUri($company),
            'location' => $location,
            'locationSettings' => $location ? LocationSetting::for($location) : null,
            'digitalUrl' => $digitalUrl,
            'qr' => $digitalUrl ? self::qr($digitalUrl) : null,
        ];
    }

    private static function logoDataUri(CompanySetting $company): ?string
    {
        if (! $company->logo_path || ! Storage::disk('public')->exists($company->logo_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function pdf(string $view, array $data): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view($view, $data)->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
