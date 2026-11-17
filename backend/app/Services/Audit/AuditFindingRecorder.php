<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Queries\Audit\AuditChainReport;
use App\Services\Notifications\OwnerAlerts;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Stores a failed audit verification (13 §14), logs it as an error and tells the organisation owners, once per distinct break:
 * a chain that stays broken the same way is not reported again every night. Never touches the audit chain itself.
 */
final class AuditFindingRecorder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly OwnerAlerts $owners,
    ) {}

    /** @return bool whether the break was new */
    public function record(string $tenantId, AuditChainReport $report, bool $full): bool
    {
        $detail = (string) $report->detail;
        $problem = (string) $report->problem;
        $db = $this->db->connection();
        $inserted = $db->affectingStatement(
            'INSERT INTO audit_chain_findings (id, tenant_id, broken_at_id, problem, detail, full_check, fingerprint, detected_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (tenant_id, fingerprint) DO NOTHING',
            [$this->ids->next(), $tenantId, $report->brokenAtId, $problem, $detail, $full ? 'true' : 'false',
                hash('sha256', "{$problem}|{$report->brokenAtId}|{$detail}"), $this->clock->now()->format('Y-m-d H:i:s.uP')],
        );
        if ($inserted === 0) {
            return false;
        }
        Log::error('Audit chain verification failed.', ['tenant_id' => $tenantId, 'broken_at_id' => $report->brokenAtId, 'problem' => $problem, 'detail' => $detail, 'full' => $full]);

        $company = $db->selectOne('SELECT id FROM companies ORDER BY created_at, id LIMIT 1');
        if ($company !== null) {
            $this->owners->send(
                $tenantId, (string) $company->id, 'audit_chain_broken', 'The audit trail failed its nightly check',
                "The audit trail no longer verifies ({$problem} at event {$report->brokenAtId}). Contact support; the books themselves are not changed by this check.",
                'audit-chain:'.substr(hash('sha256', "{$problem}|{$report->brokenAtId}|{$detail}"), 0, 16),
            );
        }

        return true;
    }
}
