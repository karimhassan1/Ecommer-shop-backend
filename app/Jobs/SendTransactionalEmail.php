<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Every transactional email goes through this job instead of being sent on the
 * request that triggered it.
 *
 * Why: verification codes, password resets, order receipts and seller notices
 * are all triggered by a user waiting for a response. Talking to an SMTP server
 * can take seconds (or time out), so the request would stall for something the
 * customer does not need to wait for. The job is pushed onto the queue and a
 * worker (`php artisan queue:work`) delivers it afterwards.
 *
 * The job is retried a few times, because a transient SMTP hiccup should not
 * silently lose an order confirmation.
 */
class SendTransactionalEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Attempts before the job is moved to the failed-jobs table. */
    public int $tries = 3;

    /** Seconds to wait before each retry (attempt 1, 2, 3). */
    public int $backoff = 10;

    /** Give a slow SMTP server room before the attempt is considered lost. */
    public int $timeout = 60;

    public function __construct(
        public string $recipient,
        public Mailable $mailable,
    ) {}

    public function handle(): void
    {
        Mail::to($this->recipient)->send($this->mailable);
    }

    /**
     * Last word when every attempt failed: keep the reason in the log so a
     * support person can see which address was affected.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Transactional email could not be delivered after retries.', [
            'to' => $this->recipient,
            'mailable' => $this->mailable::class,
            'error' => $exception?->getMessage(),
        ]);
    }
}
