<?php

namespace App\Mail;

use App\Models\CompanySetting;
use App\Models\PurchaseOrder;
use App\Support\PurchaseOrderPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;

    public int $purchaseOrderId;

    public function __construct(PurchaseOrder|int $purchaseOrder)
    {
        $this->purchaseOrder = $purchaseOrder instanceof PurchaseOrder
            ? $purchaseOrder
            : PurchaseOrder::query()->findOrFail($purchaseOrder);
        $this->purchaseOrderId = $this->purchaseOrder->id;
    }

    public function envelope(): Envelope
    {
        $companyName = CompanySetting::current()->name ?: config('app.name');

        return new Envelope(
            subject: "Purchase Order {$this->purchaseOrder->number} from {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-order',
            with: [
                'po' => $this->purchaseOrder->loadMissing(['supplier', 'location', 'lines.variant.product']),
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
                fn () => PurchaseOrderPdf::generate($this->purchaseOrder),
                "{$this->purchaseOrder->number}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
