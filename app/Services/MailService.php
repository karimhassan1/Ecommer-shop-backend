<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmail;
use App\Mail\ContactMessageMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusChangedMail;
use App\Mail\PasswordResetCodeMail;
use App\Mail\PasswordResetMail;
use App\Mail\SellerOrderPlacedMail;
use App\Mail\SellerOrderStatusMail;
use App\Mail\WelcomeMail;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Central place where every transactional email is sent from.
 *
 * Mail is always queued (see App\Jobs\SendTransactionalEmail) so an API call
 * never waits on SMTP, and a mail failure can never break a checkout or a
 * sign-up: the customer's request has already been answered by the time the
 * worker tries to deliver.
 */
class MailService
{
    public function sendWelcome(User $user): void
    {
        $this->send($user->email, new WelcomeMail(
            customerName: $user->name,
            shopUrl: rtrim((string) config('app.frontend_url'), '/'),
        ));
    }

    /**
     * Send the password reset link, returns the broker status string.
     *
     * @deprecated The shop resets passwords with a six digit code
     *             (see PasswordResetCodeService). Kept because the Laravel
     *             broker is still wired in `AppServiceProvider`.
     */
    public function sendPasswordResetLink(string $email): string
    {
        $user = User::whereEmail($email)->first();

        if (! $user) {
            // Do not leak which addresses are registered.
            return Password::RESET_LINK_SENT;
        }

        $token = $this->passwordBroker()->createToken($user);

        $this->send($user->email, new PasswordResetMail(
            customerName: $user->name,
            resetUrl: $this->resetUrl($user, $token),
            expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
        ));

        return Password::RESET_LINK_SENT;
    }

    /**
     * Send the six digit password reset code.
     *
     * The code is generated and stored by PasswordResetCodeService; this only
     * delivers it, so the mail never has to know how the code is kept.
     */
    public function sendPasswordResetCode(User $user, string $code, int $expiresInMinutes = 15): void
    {
        $this->send($user->email, new PasswordResetCodeMail(
            customerName: $user->name,
            code: $code,
            expiresInMinutes: $expiresInMinutes,
        ));
    }

    public function sendOrderConfirmation(Order $order): void
    {
        if (! $order->email) {
            return;
        }

        $this->send($order->email, new OrderConfirmationMail($order));

        // Every seller whose products are in the order is told about it too.
        $this->sendSellerOrderNotifications($order);
    }

    /**
     * Tell each seller that one of their products was ordered.
     *
     * A single order can span several sellers, so the lines are grouped by
     * product owner and each seller receives only their own lines plus the
     * shipping address — never the whole basket.
     */
    public function sendSellerOrderNotifications(Order $order): void
    {
        $order->loadMissing('items.product.seller');

        /** @var \Illuminate\Support\Collection<int, \App\Models\OrderItem> $lines */
        $lines = $order->items->filter(
            fn ($line): bool => $line->product?->seller_id !== null
        );

        foreach ($lines->groupBy(fn ($line): int => (int) $line->product->seller_id) as $sellerId => $sellerLines) {
            $seller = $sellerLines->first()->product->seller;

            if (! $seller || ! $seller->email) {
                continue;
            }

            $this->send($seller->email, new SellerOrderPlacedMail(
                order: $order,
                seller: $seller,
                lines: $sellerLines->values(),
                sellerTotal: (float) $sellerLines->sum(
                    fn ($line): float => (float) $line->line_total
                ),
            ));
        }
    }

    public function sendOrderStatusChanged(Order $order, string $previousStatus): void
    {
        if ($order->email) {
            $this->send($order->email, new OrderStatusChangedMail($order, $previousStatus));
        }

        // The sellers of the products in the order need to know as well: they
        // are the ones who ship and they see the same order in their panel.
        $order->loadMissing('items.product.seller');

        $sellers = $order->items
            ->map(fn ($line) => $line->product?->seller)
            ->filter()
            ->unique('id');

        foreach ($sellers as $seller) {
            if (! $seller->email) {
                continue;
            }

            $this->send($seller->email, new SellerOrderStatusMail(
                order: $order,
                seller: $seller,
                lines: $order->items->filter(
                    fn ($line): bool => (int) $line->product?->seller_id === (int) $seller->id
                )->values(),
                previousStatus: $previousStatus,
            ));
        }
    }

    /**
     * Forward a storefront contact form submission to the shop owner.
     */
    public function sendContactNotification(ContactMessage $contact): void
    {
        $recipient = (string) config('app.admin_email');

        if ($recipient === '') {
            return;
        }

        $this->send($recipient, new ContactMessageMail($contact));
    }

    public function verificationUrl(User $user): string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return $frontend.'/auth/verify-email';
    }

    protected function resetUrl(User $user, string $token): string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return $frontend.'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]);
    }

    protected function passwordBroker(): PasswordBroker
    {
        return Password::broker();
    }

    /**
     * Hand a mailable to the queue.
     *
     * Nothing here talks to an SMTP server: the job is pushed and the API
     * returns immediately, so a slow or unreachable mail host can no longer
     * stall registration, checkout or a contact form submission. The mail is
     * delivered by a queue worker (`php artisan queue:work`).
     *
     * Returns false only when the message could not even be queued (for
     * example no queue driver is configured).
     */
    protected function send(string $to, object $mailable): bool
    {
        try {
            SendTransactionalEmail::dispatch($to, $mailable);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Transactional email could not be queued.', [
                'to' => $to,
                'mailable' => $mailable::class,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
