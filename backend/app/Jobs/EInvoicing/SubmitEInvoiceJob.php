<?php

declare(strict_types=1);

namespace App\Jobs\EInvoicing;

use App\Actions\EInvoicing\SubmitEInvoice;
use App\Support\Tenancy\TenantAware;
use App\Support\Tenancy\WithTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One attempt to submit a posted document to the tax authority (plan 3c.27). Retries are not queue retries: a failed attempt stores its
 * own `next_attempt_at` and `einvoice:sweep` queues the job again when it is due, so nothing waits in the queue for hours.
 */
final class SubmitEInvoiceJob implements ShouldQueue, TenantAware
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $tenant, public readonly string $submissionId)
    {
        $this->onQueue('default');
    }

    public function tenantId(): string
    {
        return $this->tenant;
    }

    public function companyId(): ?string
    {
        return null;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [app(WithTenantContext::class)];
    }

    public function handle(SubmitEInvoice $action): void
    {
        $action->handle($this->tenant, $this->submissionId);
    }
}
