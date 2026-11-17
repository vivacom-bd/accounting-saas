<?php

declare(strict_types=1);

namespace App\Jobs\Ledger;

use App\Queries\Ledger\FindLedgerDrift;
use App\Services\Ledger\DriftFindingStore;
use App\Support\Tenancy\TenantAware;
use App\Support\Tenancy\WithTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily, per tenant (13 §9, §15): recompute balances from posted lines and record what differs from the stored ones. */
final class CheckTenantLedgerDrift implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(public readonly string $tenant)
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

    public function handle(FindLedgerDrift $find, DriftFindingStore $store): void
    {
        $store->record($this->tenant, $find());
    }
}
