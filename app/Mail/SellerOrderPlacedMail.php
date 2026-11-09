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
 * "You have a new order" notification for a product seller.
 *
 * The mailable is handed the seller *and* only the order lines that belong to
 * them, so a seller never learns what else the customer bought from someone
 * else.
 */
class SellerOrderPlacedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderItem>  $lines
     */
    public function __construct(
        public Order $order,
        public User $seller,
        public $lines,
        public float $sellerTotal,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New order #'.$this->order->id.' contains your products',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.seller-order-placed',
            with: [
                'order' => $this->order,
                'seller' => $this->seller,
                'lines' => $this->lines,
                'sellerTotal' => $this->sellerTotal,
                'dashboardUrl' => rtrim((string) config('app.frontend_url'), '/').'/admin/orders',
            ],
        );
    }
}
