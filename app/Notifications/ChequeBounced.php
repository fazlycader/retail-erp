<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the location's managers (and admins) that a cheque bounced and the order is owed again. */
class ChequeBounced extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payment;

        return (new MailMessage)
            ->subject("Cheque bounced: {$p->number}")
            ->line("Cheque {$p->cheque_number} ({$p->cheque_bank}) for ".Money::fromCents($p->amount)->format().' bounced.')
            ->line($p->salesOrder
                ? "Sales order {$p->salesOrder->number} is owed ".Money::fromCents($p->salesOrder->balance_due)->format().' again.'
                : 'It was an on-account payment.')
            ->line('Customer: '.($p->customer?->name ?? 'walk-in').($p->customer ? ' ('.$p->customer->displayPhone().')' : ''))
            ->action('Open the order', $p->sales_order_id ? route('sales.orders.show', $p->sales_order_id) : route('sales.payments.index'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'payment_id' => $this->payment->id,
            'number' => $this->payment->number,
            'cheque_number' => $this->payment->cheque_number,
            'amount' => $this->payment->amount,
            'sales_order_id' => $this->payment->sales_order_id,
            'message' => "Cheque {$this->payment->cheque_number} on {$this->payment->number} bounced.",
        ];
    }
}
