<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Ledger\CheckTenantLedgerDrift;
use App\Support\Tenancy\TenantsDueFor;
use Illuminate\Console\Command;

/** Daily: queue the balance drift check for every live tenant (13 §9, §15, plan 4.2). */
final class CheckLedgerDrift extends Command
{
    protected $description = 'Queue the stored-balance drift check for every live tenant';

    protected $signature = 'ledger:check-drift';

    public function handle(TenantsDueFor $tenants): int
    {
        $count = 0;
        foreach ($tenants('ledger_drift') as $tenantId) {
            CheckTenantLedgerDrift::dispatch($tenantId);
            $count++;
        }
        $this->info("Queued {$count} drift check(s).");

        return self::SUCCESS;
    }
}
