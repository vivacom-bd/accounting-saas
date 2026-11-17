<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Ledger\DriftFinding;
use App\Services\Notifications\OwnerAlerts;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Records drift findings (13 §9) and tells the organisation owners. A finding that is already recorded (same fingerprint)
 * is left alone and nobody is told twice; only mismatches that are new since the last run are stored, logged as errors and
 * notified, one notification per owner and company. Writes only to `ledger_drift_findings` and `user_notifications`, never to
 * ledger data.
 */
final class DriftFindingStore
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly OwnerAlerts $owners,
    ) {}

    /**
     * @param  list<DriftFinding>  $findings
     * @return list<DriftFinding> the ones that were new
     */
    public function record(string $tenantId, array $findings): array
    {
        $db = $this->db->connection();
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $new = [];
        foreach ($findings as $f) {
            $inserted = $db->affectingStatement(
                'INSERT INTO ledger_drift_findings (id, tenant_id, company_id, kind, account_id, fiscal_period_id, journal_id, dimensions, expected_debit, expected_credit, stored_debit, stored_credit, fingerprint, detected_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (tenant_id, fingerprint) DO NOTHING',
                [$this->ids->next(), $tenantId, $f->companyId, $f->kind, $f->accountId, $f->fiscalPeriodId, $f->journalId, $f->dimensions,
                    $f->expectedDebit, $f->expectedCredit, $f->storedDebit, $f->storedCredit, $f->fingerprint(), $now],
            );
            if ($inserted > 0) {
                $new[] = $f;
                Log::error('Ledger drift detected.', [
                    'tenant_id' => $tenantId, 'company_id' => $f->companyId, 'kind' => $f->kind, 'account_id' => $f->accountId, 'fiscal_period_id' => $f->fiscalPeriodId,
                    'journal_id' => $f->journalId, 'expected_debit' => $f->expectedDebit, 'expected_credit' => $f->expectedCredit,
                    'stored_debit' => $f->storedDebit, 'stored_credit' => $f->storedCredit,
                ]);
            }
        }
        $this->notifyOwners($tenantId, $new);

        return $new;
    }

    /** @param list<DriftFinding> $new */
    private function notifyOwners(string $tenantId, array $new): void
    {
        $byCompany = [];
        foreach ($new as $f) {
            $byCompany[$f->companyId][] = $f;
        }
        foreach ($byCompany as $companyId => $found) {
            $key = 'drift:'.$companyId.':'.substr(hash('sha256', implode(',', array_map(fn (DriftFinding $f): string => $f->fingerprint(), $found))), 0, 16);
            $this->owners->send(
                $tenantId, (string) $companyId, 'ledger_drift', 'The ledger needs a look',
                'The nightly check found '.count($found).' difference(s) between the stored balances and the posted journals. Posted entries are untouched; ask support to check the figures before relying on reports.',
                $key,
            );
        }
    }
}
