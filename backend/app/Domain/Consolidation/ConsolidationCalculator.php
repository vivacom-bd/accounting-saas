<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

use App\Domain\Shared\DomainRuleViolation;

/**
 * The consolidation worksheet (D16.3, D16.4), pure: plain data in, plain data out, no I/O and no clock. All amounts are
 * integer minor units, net = debit − credit.
 *
 * For every member:
 *  1. Its own figures: balance sheet balances at the end, profit and loss movement of its window, and retained earnings as
 *     the balancing figure (what the member's equity holds that is not this window's result), so each column balances.
 *  2. Translation (IAS 21) when its currency differs from the group's: assets and liabilities at the closing rate; income
 *     and expense at the period average (or the group's override); equity at historical rates, that is its translated
 *     value at the start of the window plus each movement in the window at the rate of its own day. Whatever is left is
 *     the translation reserve: the balancing line of the translated column, which also absorbs every rounding difference.
 *     The opening must explain the local equity exactly; if equity moved in a period already consolidated, its historical
 *     rate is unknown and the run is blocked.
 * Then the group level: posted consolidation entries dated up to the end are added as adjustments. A line on a profit and
 * loss account dated before the run belongs to retained earnings by now, not to this run's result.
 *
 * Intercompany eliminations and the net-investment reclassification (plan 3c.29c, see Intercompany) are proposed entries of
 * the run, shown as cells now and posted when the run is approved. Acquisition accounting with non-controlling interests
 * (3c.29d) adds its own; until then the reserve here is the group's whole reserve before any split.
 */
final class ConsolidationCalculator
{
    public function run(RunInput $in): RunResult
    {
        $re = $in->roleAccount(GroupRoles::RETAINED_EARNINGS) ?? throw DomainRuleViolation::single('group_role_missing', 'The group has no retained earnings account.');
        $trAccount = $in->roleAccount(GroupRoles::TRANSLATION_RESERVE) ?? throw DomainRuleViolation::single('group_role_missing', 'The group has no translation reserve account.');

        $local = [];
        $translated = [];
        $states = [];
        $trMove = 0;
        $consolidated = [];
        foreach ($in->members as $m) {
            [$local[$m->id], $translated[$m->id], $states[$m->id], $move] = $this->member($m, $in, $re, $trAccount);
            $trMove += $move;
            foreach ($translated[$m->id] as $acc => $v) {
                $consolidated[$acc] = ($consolidated[$acc] ?? 0) + $v;
            }
        }

        $cells = [];
        foreach ($in->entryLines as $line) {
            $account = $line['account_id'];
            if ($line['date'] < $in->from && $in->isProfitAndLoss($account)) {
                $account = $re;
            }
            $cells[] = ['source' => 'entry', 'member_id' => $line['member_id'], 'account_id' => $account, 'net' => $line['net'], 'entry_id' => $line['entry_id']];
        }
        // Intercompany eliminations and the net-investment reclassification of this run (D16.7): proposed entries, posted
        // when the run is approved, shown in the worksheet now.
        $ic = (new Intercompany)->run($in, $re, $trAccount);
        foreach ($ic['proposed'] as $p) {
            foreach ($p['lines'] as $l) {
                $cells[] = ['source' => $p['kind'], 'member_id' => $l['member_id'], 'account_id' => $l['account_id'], 'net' => $l['net'], 'entry_id' => null];
            }
        }
        foreach ($cells as $c) {
            $consolidated[$c['account_id']] = ($consolidated[$c['account_id']] ?? 0) + $c['net'];
        }

        $profit = 0;
        foreach ($consolidated as $acc => $v) {
            if ($in->isProfitAndLoss((string) $acc)) {
                $profit -= $v;
            }
        }

        return new RunResult($local, $translated, $cells, $this->nonZero($consolidated), $states, $ic['proposed'], [
            'profit' => $profit, 'translation_difference' => $trMove,
        ], $ic['matches']);
    }

    /**
     * @return array{0: array<string, int>, 1: array<string, int>, 2: array{local: array<string, int>, translated: array<string, int>, tr: int}, 3: int}
     */
    private function member(MemberInput $m, RunInput $in, string $re, string $trAccount): array
    {
        $local = [];
        foreach ($m->bs as $acc => $v) {
            if ((string) $acc !== $re) {
                $local[(string) $acc] = $v;
            }
        }
        $plSum = 0;
        foreach ($m->pl as $acc => $v) {
            $local[(string) $acc] = ($local[(string) $acc] ?? 0) + $v;
            $plSum += $v;
        }
        $local[$re] = -array_sum($local);   // retained earnings: the balancing figure of the member's own books

        $foreign = $m->currency !== $in->currency;
        $trTotal = 0;
        $openingTr = 0;
        if (! $foreign) {
            $translated = $local;
        } else {
            $closing = $this->rate($m->closingRate, $m->id, 'closing');
            $average = $this->rate($m->averageRate, $m->id, 'average');
            if ($m->opening === null) {
                throw DomainRuleViolation::single('consolidation_opening_missing', "Member {$m->id} is translated but has no opening position; consolidate its periods in order from its acquisition.");
            }
            $openingTr = $m->opening['tr'];
            $openL = $m->opening['local'];
            $openT = $m->opening['translated'];
            $moveL = [];
            $moveT = [];
            foreach ($m->equityMovements as $mv) {
                $rate = $this->rate($mv['rate'], $m->id, "historical on {$mv['date']}");
                $moveL[$mv['account_id']] = ($moveL[$mv['account_id']] ?? 0) + $mv['net'];
                $moveT[$mv['account_id']] = ($moveT[$mv['account_id']] ?? 0) + Rounding::translate($mv['net'], $rate, $m->scale, $in->scale);
            }

            $translated = [];
            foreach ($local as $acc => $v) {
                $type = $in->accounts[$acc]['type'] ?? 'asset';
                $translated[$acc] = match ($type) {
                    'asset', 'liability' => Rounding::translate($v, $closing, $m->scale, $in->scale),
                    'income', 'expense' => Rounding::translate($v, $average, $m->scale, $in->scale),
                    default => ($openT[$acc] ?? 0) + ($moveT[$acc] ?? 0),
                };
            }
            // Historical equity must explain the books exactly: the opening plus the window's movements.
            $equity = array_unique([...array_keys(array_filter($local, fn (string $acc): bool => ($in->accounts[$acc]['type'] ?? '') === 'equity', ARRAY_FILTER_USE_KEY)), ...array_map('strval', array_keys($openL)), ...array_map('strval', array_keys($moveL))]);
            foreach ($equity as $acc) {
                if (($local[$acc] ?? 0) !== ($openL[$acc] ?? 0) + ($moveL[$acc] ?? 0)) {
                    throw DomainRuleViolation::single('consolidation_opening_moved', "Member {$m->id}: equity account {$acc} changed before this period after its opening position was fixed; reverse the earlier run and consolidate again.");
                }
                if (! array_key_exists($acc, $translated)) {
                    $translated[$acc] = ($openT[$acc] ?? 0) + ($moveT[$acc] ?? 0);
                }
            }
            $trTotal = -array_sum($translated);
            $translated[$trAccount] = ($translated[$trAccount] ?? 0) + $trTotal;
        }

        // The closing position, the opening of the next run: equity with this window's result in retained earnings.
        $plTranslated = 0;
        foreach ($translated as $acc => $t) {
            if ($in->isProfitAndLoss((string) $acc)) {
                $plTranslated += $t;
            }
        }
        $closeLocal = [];
        $closeTranslated = [];
        foreach ($local as $acc => $v) {
            if (($in->accounts[$acc]['type'] ?? '') !== 'equity') {
                continue;
            }
            $closeLocal[$acc] = $v + ($acc === $re ? $plSum : 0);
            $closeTranslated[$acc] = ($foreign ? ($translated[$acc] ?? 0) : $v) + ($acc === $re ? $plTranslated : 0);
            if ($acc === $trAccount && $foreign) {
                $closeTranslated[$acc] -= $trTotal;   // the member's own reserve account, without this run's plug
            }
        }

        return [$this->nonZero($local), $this->nonZero($translated), ['local' => $closeLocal, 'translated' => $closeTranslated, 'tr' => $trTotal], $trTotal - $openingTr];
    }

    /**
     * @param  array<array-key, int>  $rows
     * @return array<string, int>
     */
    private function nonZero(array $rows): array
    {
        $out = [];
        foreach ($rows as $k => $v) {
            if ($v !== 0) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }

    private function rate(?string $rate, string $memberId, string $which): string
    {
        return $rate ?? throw DomainRuleViolation::single('fx_rate_missing', "No rate ({$which}) for member {$memberId}.");
    }
}
