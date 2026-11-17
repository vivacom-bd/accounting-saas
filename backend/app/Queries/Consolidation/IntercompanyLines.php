<?php

declare(strict_types=1);

namespace App\Queries\Consolidation;

use App\Support\Reports\ReportRunner;

/**
 * A member company's posted lines on its intercompany accounts (D16.7), summed per account, contact and transaction
 * currency, from the same lines every balance uses: originals marked reversed and their mirrors included, closing journals
 * and their reversals left out. `fc` is the amount in the transaction currency; revaluation lines move base only, so their
 * foreign amount is left out (as in the revaluation itself). With `$byDay` the rows are also per journal date (equity,
 * which translates at the rate of its own day).
 *
 * Read-only, on the report connection.
 */
final class IntercompanyLines
{
    public function __construct(private readonly ReportRunner $runner) {}

    /**
     * @param  list<string>  $accountIds
     * @return list<array{account_id: string, contact_id: string|null, currency: string, day: string|null, net: int, fc: int}>
     */
    public function __invoke(string $companyId, array $accountIds, ?string $from, string $to, bool $byDay = false): array
    {
        if ($accountIds === [] || ($from !== null && $from > $to)) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($accountIds), '?'));
        $day = $byDay ? 'j.journal_date::text' : 'NULL::text';
        $rows = $this->runner->db()->select(
            "SELECT l.account_id, l.contact_id, l.currency, {$day} AS day,
                    (SUM(l.debit) - SUM(l.credit))::bigint AS net,
                    SUM(CASE WHEN j.source_type IS DISTINCT FROM 'fx_revaluation' THEN l.amount_fc ELSE 0 END)::bigint AS fc
               FROM journal_lines l JOIN journals j ON j.id = l.journal_id
              WHERE l.company_id = ? AND j.company_id = ? AND j.status IN ('posted', 'reversed')
                AND j.journal_type <> 'closing'
                AND (j.reversal_of_id IS NULL OR j.reversal_of_id NOT IN (SELECT c.id FROM journals c WHERE c.company_id = ? AND c.journal_type = 'closing'))
                AND (?::date IS NULL OR j.journal_date >= ?::date) AND j.journal_date <= ? AND l.account_id IN ({$marks})
              GROUP BY l.account_id, l.contact_id, l.currency, {$day}
              ORDER BY 1, 2, 3, 4",
            [$companyId, $companyId, $companyId, $from, $from, $to, ...$accountIds],
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'account_id' => (string) $r->account_id, 'contact_id' => $r->contact_id === null ? null : (string) $r->contact_id,
                'currency' => (string) $r->currency, 'day' => $r->day === null ? null : substr((string) $r->day, 0, 10),
                'net' => (int) $r->net, 'fc' => (int) $r->fc,
            ];
        }

        return $out;
    }

    /**
     * The exchange difference booked in profit or loss on these accounts over the window: the base movement of the
     * revaluation journals and their reversals (a reversal keeps its original's source type), net = debit − credit on the
     * accounts themselves.
     *
     * @param  list<string>  $accountIds
     * @return array<string, int> account id → net
     */
    public function revaluation(string $companyId, array $accountIds, string $from, string $to): array
    {
        if ($accountIds === [] || $from > $to) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($accountIds), '?'));
        $rows = $this->runner->db()->select(
            "SELECT l.account_id, (SUM(l.debit) - SUM(l.credit))::bigint AS net
               FROM journal_lines l JOIN journals j ON j.id = l.journal_id
              WHERE l.company_id = ? AND j.company_id = ? AND j.status IN ('posted', 'reversed') AND j.source_type = 'fx_revaluation'
                AND j.journal_date BETWEEN ? AND ? AND l.account_id IN ({$marks})
              GROUP BY l.account_id",
            [$companyId, $companyId, $from, $to, ...$accountIds],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->account_id] = (int) $r->net;
        }

        return $out;
    }

    /**
     * The realised exchange differences of settling or repaying these accounts over the window: the lines on the realised FX
     * accounts in journals that also post to one of them, revaluations left out, net = debit − credit per FX account.
     *
     * @param  list<string>  $accountIds
     * @param  list<string>  $fxAccountIds
     * @return array<string, int> FX account id → net
     */
    public function settledAgainst(string $companyId, array $accountIds, array $fxAccountIds, string $from, string $to): array
    {
        if ($accountIds === [] || $fxAccountIds === [] || $from > $to) {
            return [];
        }
        $loans = implode(', ', array_fill(0, count($accountIds), '?'));
        $fx = implode(', ', array_fill(0, count($fxAccountIds), '?'));
        $rows = $this->runner->db()->select(
            "SELECT l.account_id, (SUM(l.debit) - SUM(l.credit))::bigint AS net
               FROM journal_lines l JOIN journals j ON j.id = l.journal_id
              WHERE l.company_id = ? AND j.company_id = ? AND j.status IN ('posted', 'reversed')
                AND j.journal_type <> 'closing' AND j.source_type IS DISTINCT FROM 'fx_revaluation'
                AND j.journal_date BETWEEN ? AND ? AND l.account_id IN ({$fx})
                AND EXISTS (SELECT 1 FROM journal_lines o WHERE o.journal_id = j.id AND o.account_id IN ({$loans}))
              GROUP BY l.account_id",
            [$companyId, $companyId, $from, $to, ...$fxAccountIds, ...$accountIds],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->account_id] = (int) $r->net;
        }

        return $out;
    }
}
