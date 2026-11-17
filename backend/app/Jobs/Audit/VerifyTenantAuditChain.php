<?php

declare(strict_types=1);

namespace App\Jobs\Audit;

use App\Queries\Audit\VerifyAuditChain;
use App\Services\Audit\AuditFindingRecorder;
use App\Support\Tenancy\TenantAware;
use App\Support\Tenancy\WithTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verifies one tenant's audit chain and seals (13 §14); a break is recorded and the owners are told. Queued per tenant by
 * `audit:verify-all`. The nightly run starts after the newest valid seal, the weekly one covers the whole chain.
 */
final class VerifyTenantAuditChain implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(public readonly string $tenant, public readonly bool $full = false)
    {
        $this->onQueue('maintenance');
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

    public function handle(VerifyAuditChain $verify, AuditFindingRecorder $recorder): void
    {
        $report = $verify(! $this->full);
        if (! $report->intact) {
            $recorder->record($this->tenant, $report, $this->full);
        }
    }
}
