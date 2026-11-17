<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Queries\Admin\VerifyAdminAuditChain;
use Illuminate\Console\Command;

/** Recomputes the platform admin audit chain; exits 1 and names the first broken event when it is not intact (plan T10). */
final class VerifyAdminAuditLog extends Command
{
    protected $description = 'Verify the platform admin audit hash chain';

    protected $signature = 'admin-audit:verify';

    public function handle(VerifyAdminAuditChain $verify): int
    {
        $report = $verify();

        if ($report['brokenAtId'] === null) {
            $this->info("Admin audit chain intact ({$report['events']} events)");

            return self::SUCCESS;
        }

        $this->error("Admin audit chain broken at event {$report['brokenAtId']}: {$report['problem']}");

        return self::FAILURE;
    }
}
