<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "An order with your products moved on" notification for a seller.
 *
 * Carries only the seller's own lines, like SellerOrderPlacedMail.
 */
class SellerOrderStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderItem>  $lines
     */
    public function __construct(
        public Order $order,
        public User $seller,
        public $lines,
        public string $previousStatus,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order #'.$this->order->id.' is now '.$this->order->status_label,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.seller-order-status',
            with: [
                'order' => $this->order,
                'seller' => $this->seller,
                'lines' => $this->lines,
                'previousLabel' => (string) config(
                    'shop.order_statuses.'.$this->previousStatus,
                    $this->previousStatus
                ),
                'dashboardUrl' => rtrim((string) config('app.frontend_url'), '/').'/admin/orders',
            ],
        );
    }
}
