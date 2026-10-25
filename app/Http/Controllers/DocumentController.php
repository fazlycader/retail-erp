<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Support\SalesDocuments;
use Illuminate\Http\Response;

/**
 * Public digital copies behind the QR code on printed documents: /doc/{token} (HTML) and /doc/{token}/pdf.
 * Tokens are 40 random characters, so the link is the permission.
 */
class DocumentController extends Controller
{
    public function show(string $token): Response
    {
        [$view, $data] = $this->resolve($token);

        return response()->view($view, [...$data, 'pdfUrl' => route('documents.pdf', $token)])
            ->header('X-Robots-Tag', 'noindex');
    }

    public function pdf(string $token): Response
    {
        $order = $this->find($token);

        [$bytes, $name] = match (true) {
            $order instanceof SalesOrder => [SalesDocuments::invoicePdf($order), $order->invoice_number],
            $order instanceof Payment => [SalesDocuments::receiptPdf($order), $order->number],
            default => [SalesDocuments::creditNotePdf($order), $order->number],
        };

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolve(string $token): array
    {
        $document = $this->find($token);

        return match (true) {
            $document instanceof SalesOrder => ['documents.invoice', SalesDocuments::invoiceData($document)],
            $document instanceof Payment => ['documents.receipt', SalesDocuments::receiptData($document)],
            default => ['documents.credit-note', SalesDocuments::creditNoteData($document)],
        };
    }

    private function find(string $token): SalesOrder|Payment|CreditNote
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return SalesOrder::query()->where('share_token', $token)->whereNotNull('invoice_number')->first()
            ?? Payment::query()->where('share_token', $token)->first()
            ?? CreditNote::query()->where('share_token', $token)->firstOrFail();
    }
}
