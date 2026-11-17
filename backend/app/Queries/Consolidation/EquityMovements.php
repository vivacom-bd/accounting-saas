<?php

declare(strict_types=1);

namespace App\Queries\Consolidation;

use App\Support\Reports\ReportRunner;

/**
 * Movements of a company's equity accounts per day over a window, from posted lines (originals marked reversed and their
 * mirrors included, like every balance), closing journals and their reversals left out (D7). Equity translates at the
 * historical rate of each movement's own day (13 §23 D16.4), so the run needs the date, not only the total.
 */
final class EquityMovements
{
    public function __construct(private readonly ReportRunner $runner) {}

    /**
     * @param  list<string>  $accountIds
     * @return list<array{account_id: string, date: string, net: int}>
     */
    public function __invoke(string $companyId, array $accountIds, string $from, string $to): array
    {
        if ($accountIds === [] || $from > $to) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($accountIds), '?'));
        $rows = $this->runner->db()->select(
            "SELECT l.account_id, j.journal_date::text AS day, (SUM(l.debit) - SUM(l.credit))::bigint AS net
               FROM journal_lines l JOIN journals j ON j.id = l.journal_id
              WHERE l.company_id = ? AND j.company_id = ? AND j.status IN ('posted', 'reversed')
                AND j.journal_type <> 'closing'
                AND (j.reversal_of_id IS NULL OR j.reversal_of_id NOT IN (SELECT c.id FROM journals c WHERE c.company_id = ? AND c.journal_type = 'closing'))
                AND j.journal_date BETWEEN ? AND ? AND l.account_id IN ({$marks})
              GROUP BY l.account_id, j.journal_date
             HAVING SUM(l.debit) <> SUM(l.credit)
              ORDER BY j.journal_date, l.account_id",
            [$companyId, $companyId, $companyId, $from, $to, ...$accountIds],
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['account_id' => (string) $r->account_id, 'date' => substr((string) $r->day, 0, 10), 'net' => (int) $r->net];
        }

        return $out;
    }
}
