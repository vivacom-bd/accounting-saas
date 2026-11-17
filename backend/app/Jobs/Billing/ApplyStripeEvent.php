<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Actions\Billing\ApplyStripeEventPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Applies one stored Stripe event (plan Task 12). Idempotent and safe to retry: the Action locks the stored row and
 * returns when it was processed already; a failure is recorded on the row and the job is retried with a backoff.
 */
final class ApplyStripeEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $eventRowId)
    {
        $this->onQueue('billing');
    }

    public function handle(ApplyStripeEventPayload $apply): void
    {
        $apply($this->eventRowId);
    }
}
