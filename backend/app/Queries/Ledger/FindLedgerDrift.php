<?php

declare(strict_types=1);

namespace App\Queries\Ledger;

use App\Domain\Ledger\DriftFinding;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\DatabaseManager;

/**
 * Read-only drift detector over the current tenant (13 §9): recomputes every account's period balance from posted journal
 * lines and compares it with `account_period_balances`; checks that every posted journal balances (its lines, and its header
 * totals); and that each company's stored trial balance nets to zero. The projection is never the source of truth, so
 * a mismatch is a finding about the projection, and nothing here writes.
 */
final class FindLedgerDrift
{
    public function __construct(private readonly DatabaseManager $db, private readonly TenantContext $tenant) {}

    /** @return list<DriftFinding> */
    public function __invoke(): array
    {
        $this->tenant->require();

        return [...$this->balances(), ...$this->journals(), ...$this->trialBalances()];
    }

    /** @return list<DriftFinding> */
    private function balances(): array
    {
        $dims = implode(', ', array_map(fn (int $p): string => "dim{$p}_value_id", range(1, 8)));
        $on = implode(' AND ', array_map(fn (int $p): string => "lines.dim{$p}_value_id IS NOT DISTINCT FROM proj.dim{$p}_value_id", range(1, 8)));
        $label = implode(" || ',' || ", array_map(fn (int $p): string => "coalesce(coalesce(lines.dim{$p}_value_id, proj.dim{$p}_value_id)::text, '')", range(1, 8)));

        $rows = $this->db->connection()->select(<<<SQL
            WITH lines AS (
                SELECT l.company_id, l.account_id, j.fiscal_period_id, {$dims}, sum(l.debit)::bigint AS d, sum(l.credit)::bigint AS c
                FROM journal_lines l JOIN journals j ON j.id = l.journal_id
                WHERE j.status IN ('posted', 'reversed') GROUP BY l.company_id, l.account_id, j.fiscal_period_id, {$dims}
            ), proj AS (
                SELECT company_id, account_id, fiscal_period_id, {$dims}, sum(debit)::bigint AS d, sum(credit)::bigint AS c
                FROM account_period_balances GROUP BY company_id, account_id, fiscal_period_id, {$dims}
            )
            SELECT coalesce(lines.company_id, proj.company_id) AS company_id, coalesce(lines.account_id, proj.account_id) AS account_id,
                   coalesce(lines.fiscal_period_id, proj.fiscal_period_id) AS fiscal_period_id, ({$label}) AS dimensions,
                   coalesce(lines.d, 0) AS exp_d, coalesce(lines.c, 0) AS exp_c, coalesce(proj.d, 0) AS st_d, coalesce(proj.c, 0) AS st_c
            FROM lines FULL JOIN proj ON lines.company_id = proj.company_id AND lines.account_id = proj.account_id
                AND lines.fiscal_period_id = proj.fiscal_period_id AND {$on}
            WHERE coalesce(lines.d, 0) <> coalesce(proj.d, 0) OR coalesce(lines.c, 0) <> coalesce(proj.c, 0)
            ORDER BY 1, 2, 3, 4
        SQL);

        return array_values(array_map(fn (object $o): DriftFinding => new DriftFinding(
            DriftFinding::BALANCE, (string) ($r = (array) $o)['company_id'], (string) $r['account_id'], (string) $r['fiscal_period_id'], null, (string) $r['dimensions'],
            (int) $r['exp_d'], (int) $r['exp_c'], (int) $r['st_d'], (int) $r['st_c'],
        ), $rows));
    }

    /** @return list<DriftFinding> */
    private function journals(): array
    {
        $rows = $this->db->connection()->select(<<<'SQL'
            SELECT j.id, j.company_id, j.fiscal_period_id, coalesce(sum(l.debit), 0)::bigint AS exp_d, coalesce(sum(l.credit), 0)::bigint AS exp_c,
                   j.total_debit AS st_d, j.total_credit AS st_c
            FROM journals j LEFT JOIN journal_lines l ON l.journal_id = j.id
            WHERE j.status IN ('posted', 'reversed')
            GROUP BY j.id, j.company_id, j.fiscal_period_id, j.total_debit, j.total_credit
            HAVING coalesce(sum(l.debit), 0) <> coalesce(sum(l.credit), 0) OR coalesce(sum(l.debit), 0) <> j.total_debit
                OR coalesce(sum(l.credit), 0) <> j.total_credit OR count(l.id) < 2
            ORDER BY j.id
        SQL);

        return array_values(array_map(fn (object $o): DriftFinding => new DriftFinding(
            DriftFinding::JOURNAL, (string) ($r = (array) $o)['company_id'], null, (string) $r['fiscal_period_id'], (string) $r['id'], null,
            (int) $r['exp_d'], (int) $r['exp_c'], (int) $r['st_d'], (int) $r['st_c'],
        ), $rows));
    }

    /** @return list<DriftFinding> */
    private function trialBalances(): array
    {
        $rows = $this->db->connection()->select(<<<'SQL'
            SELECT company_id, sum(debit)::bigint AS d, sum(credit)::bigint AS c FROM account_period_balances
            GROUP BY company_id HAVING sum(debit) <> sum(credit) ORDER BY company_id
        SQL);

        return array_values(array_map(fn (object $o): DriftFinding => new DriftFinding(
            DriftFinding::TRIAL_BALANCE, (string) ($r = (array) $o)['company_id'], null, null, null, null, (int) $r['c'], (int) $r['c'], (int) $r['d'], (int) $r['c'],
        ), $rows));
    }
}
