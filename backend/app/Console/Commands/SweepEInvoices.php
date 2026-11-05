<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Jobs\EInvoicing\SubmitEInvoiceJob;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Every 30 s: queue a submission job for each e-invoice submission whose time has come (plan 3c.27), across tenants through
 * `sysops.einvoice_submissions_due`. It is the retry driver; the job claims the row, so queuing one twice is harmless.
 */
final class SweepEInvoices extends Command
{
    private const int BATCH = 500;

    protected $description = 'Queue e-invoice submissions that are due (first attempts that missed the queue, and retries)';

    protected $signature = 'einvoice:sweep';

    public function handle(DatabaseManager $db, TenantContext $tenant, Clock $clock): int
    {
        if ($tenant->id() !== null) {
            throw new LogicException('The e-invoice sweep must run without a tenant context.');
        }
        $rows = $db->connection()->select('SELECT submission_id, tenant_id FROM sysops.einvoice_submissions_due(?, ?)', [$clock->now()->format('Y-m-d H:i:s.uP'), self::BATCH]);
        foreach ($rows as $row) {
            SubmitEInvoiceJob::dispatch((string) $row->tenant_id, (string) $row->submission_id);
        }
        if ($rows !== []) {
            $this->info('Queued '.count($rows).' submission job(s).');
        }

        return self::SUCCESS;
    }
}
