<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\Quotation;
use App\Support\QuotationPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Quotation $quotation;

    public int $quotationId;

    public function __construct(Quotation|int $quotation)
    {
        $this->quotation = $quotation instanceof Quotation
            ? $quotation
            : Quotation::query()->findOrFail($quotation);
        $this->quotationId = $this->quotation->id;
    }

    public function envelope(): Envelope
    {
        $companyName = CompanySetting::current()->name ?: config('app.name');

        return new Envelope(
            subject: "Quotation {$this->quotation->number} from {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation',
            with: [
                'quotation' => $this->quotation->loadMissing(['customer', 'location', 'lines']),
                'company' => CompanySetting::current(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => QuotationPdf::render($this->quotation),
                "{$this->quotation->number}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
