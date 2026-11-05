<?php

declare(strict_types=1);

namespace App\Queries\Consolidation;

use App\Models\Consolidation\ConsolidationAccountMap;
use App\Models\Consolidation\ConsolidationMember;
use App\Queries\Reports\AccountDirectory;
use App\Queries\Reports\LedgerBalances;

/**
 * Member accounts that carry a balance or movement and have no mapping (D16.2). Such an account blocks a run, so
 * nothing is left out silently. Reads on the report connection through LedgerBalances, one company at a time (a
 * group's members, not a loop over tenants).
 *
 * "Carries a balance": a non-zero cumulative net at $to (this includes balance sheet accounts and the profit and loss
 * accounts of unclosed years), or any movement from $from to $to excluding closing journals.
 */
final class UnmappedBalances
{
    public function __construct(private readonly LedgerBalances $balances, private readonly AccountDirectory $accounts) {}

    /**
     * @return list<UnmappedBalance>
     */
    public function __invoke(string $groupId, ?string $from, string $to): array
    {
        $out = [];
        foreach (ConsolidationMember::query()->where('group_id', $groupId)->orderBy('role', 'desc')->orderBy('id')->get() as $member) {
            $mapped = ConsolidationAccountMap::query()->where('member_id', $member->id)->pluck('account_id')->flip()->all();
            $net = [];
            foreach ($this->balances->totals($member->company_id, null, $to, false) as $row) {
                $net[$row->accountId] = ($net[$row->accountId] ?? 0) + $row->debit - $row->credit;
            }
            $dr = [];
            $cr = [];
            if ($from !== null) {
                foreach ($this->balances->totals($member->company_id, $from, $to, true) as $row) {
                    $dr[$row->accountId] = ($dr[$row->accountId] ?? 0) + $row->debit;
                    $cr[$row->accountId] = ($cr[$row->accountId] ?? 0) + $row->credit;
                }
            }
            foreach ($this->accounts->all($member->company_id) as $a) {
                $n = $net[$a->id] ?? 0;
                $d = $dr[$a->id] ?? 0;
                $c = $cr[$a->id] ?? 0;
                if (isset($mapped[$a->id]) || ($n === 0 && $d === 0 && $c === 0)) {
                    continue;
                }
                $out[] = new UnmappedBalance($member->id, $member->company_id, $a->id, $a->code, $a->name, $a->type, $n, $d, $c);
            }
        }

        return $out;
    }
}
