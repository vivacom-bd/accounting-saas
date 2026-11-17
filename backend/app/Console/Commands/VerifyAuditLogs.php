<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Audit\VerifyTenantAuditChain;
use App\Support\Tenancy\TenantsDueFor;
use Illuminate\Console\Command;

/** Nightly: queue a verification of every live tenant's audit chain and seals (13 §14, plan 4.2). `--full` re-checks the whole chain. */
final class VerifyAuditLogs extends Command
{
    protected $description = 'Queue an audit-chain verification for every live tenant';

    protected $signature = 'audit:verify-all {--full : Check the whole chain, not only what follows the newest valid seal}';

    public function handle(TenantsDueFor $tenants): int
    {
        $count = 0;
        foreach ($tenants('audit_verify') as $tenantId) {
            VerifyTenantAuditChain::dispatch($tenantId, (bool) $this->option('full'));
            $count++;
        }
        $this->info("Queued {$count} verification(s).");

        return self::SUCCESS;
    }
}
