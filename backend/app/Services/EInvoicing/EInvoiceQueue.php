<?php

declare(strict_types=1);

namespace App\Services\EInvoicing;

use App\Actions\EInvoicing\SubmitEInvoice;
use App\Jobs\EInvoicing\SubmitEInvoiceJob;
use Throwable;

/**
 * Queues the submission job (plan 3c.27). With a real queue the job runs in a worker, in its own tenant context. With the `sync` queue
 * (local development and tests) a tenant-aware job cannot run inside the caller's own tenant context, so the attempt is made right here
 * instead, after the posting has long committed (callers are the outbox relay and the retry action). A failure here is never raised:
 * the outcome is recorded on the submission, and `einvoice:sweep` picks up anything left.
 */
final class EInvoiceQueue
{
    public function __construct(private readonly SubmitEInvoice $submit) {}

    public function push(string $tenantId, string $submissionId): void
    {
        if (config('queue.default') !== 'sync') {
            SubmitEInvoiceJob::dispatch($tenantId, $submissionId);

            return;
        }
        try {
            $this->submit->handle($tenantId, $submissionId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
