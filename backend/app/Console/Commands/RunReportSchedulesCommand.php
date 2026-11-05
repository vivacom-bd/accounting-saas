<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Reports\RunTenantReportSchedules;
use App\Support\Tenancy\TenantsDueFor;
use Illuminate\Console\Command;

/** Hourly: queue the scheduled-report pass for every live tenant (13 §15, plan 3c.23). */
final class RunReportSchedulesCommand extends Command
{
    protected $description = 'Queue the scheduled report deliveries that are due, for every live tenant';

    protected $signature = 'reports:run-schedules';

    public function handle(TenantsDueFor $tenants): int
    {
        $count = 0;
        foreach ($tenants('report_schedules') as $tenantId) {
            RunTenantReportSchedules::dispatch($tenantId);
            $count++;
        }
        $this->info("Queued {$count} scheduled report pass(es).");

        return self::SUCCESS;
    }
}
