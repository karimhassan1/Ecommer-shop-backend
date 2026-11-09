<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The six digit code that finishes email verification.
 *
 * The signed link lives in EmailVerificationMail; this one is the code the
 * customer types into the verification page.
 */
class EmailVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public string $code,
        public int $expiresInMinutes = 15,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your email verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email-code',
            with: [
                'customerName' => $this->customerName,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
