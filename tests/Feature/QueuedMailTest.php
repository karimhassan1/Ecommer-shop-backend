<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Mail\ContactMessageMail;
use App\Mail\WelcomeMail;
use App\Models\ContactMessage;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Transactional email must be *queued*, never sent on the request itself.
 *
 * The API response is what the customer is waiting for; talking to an SMTP
 * server inside that request is what made sign-up and checkout slow when the
 * mail host was slow. Every send therefore becomes a `SendTransactionalEmail`
 * job that a worker delivers later.
 */
class QueuedMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_welcome_mail_is_queued_not_sent_inline(): void
    {
        Queue::fake();
        Mail::fake();

        $user = User::factory()->create();

        app(MailService::class)->sendWelcome($user);

        Queue::assertPushed(SendTransactionalEmail::class, function (SendTransactionalEmail $job) use ($user): bool {
            return $job->recipient === $user->email
                && $job->mailable instanceof WelcomeMail;
        });

        // Nothing left the building on this request.
        Mail::assertNothingSent();
    }

    public function test_a_contact_message_is_queued_for_the_shop_owner(): void
    {
        Queue::fake();

        $contact = ContactMessage::create([
            'name' => 'Ayesha',
            'email' => 'ayesha@example.com',
            'subject' => 'Delivery question',
            'message' => 'Do you deliver to Multan?',
        ]);

        app(MailService::class)->sendContactNotification($contact);

        Queue::assertPushed(SendTransactionalEmail::class, function (SendTransactionalEmail $job): bool {
            return $job->mailable instanceof ContactMessageMail;
        });
    }

    public function test_the_job_sends_the_mail_when_the_worker_runs_it(): void
    {
        Mail::fake();

        $job = new SendTransactionalEmail(
            'customer@example.com',
            new WelcomeMail(customerName: 'Isabella', shopUrl: 'https://roserabloom.pk'),
        );

        $job->handle();

        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail): bool {
            return $mail->hasTo('customer@example.com');
        });
    }

    public function test_the_job_is_retried_before_it_is_given_up_on(): void
    {
        $job = new SendTransactionalEmail(
            'customer@example.com',
            new WelcomeMail(customerName: 'Isabella', shopUrl: 'https://roserabloom.pk'),
        );

        // A transient SMTP hiccup must not lose an order confirmation.
        $this->assertGreaterThan(1, $job->tries);
        $this->assertGreaterThan(0, $job->backoff);
    }
}
