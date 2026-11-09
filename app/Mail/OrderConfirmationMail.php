<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your order #'.$this->order->id.' is confirmed',
        );
    }

    public function content(): Content
    {
        $this->order->loadMissing('items.product');

        return new Content(
            view: 'emails.order-confirmation',
            with: [
                'order' => $this->order,
                'lines' => $this->order->items,
                'accountUrl' => rtrim((string) config('app.frontend_url'), '/').'/account/orders',
            ],
        );
    }
}
