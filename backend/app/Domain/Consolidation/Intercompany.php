<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

use App\Domain\Shared\DomainRuleViolation;

/**
 * Intercompany matching and eliminations (13 §23 D16.7, plan 3c.29c), pure: plain data in, plain data out.
 *
 * Each member brings slices of intragroup items: a class, the counterparty member, the group account and its local amount
 * (balance sheet at the end, profit and loss over the member's window, a dividend paid out of equity per day with that
 * day's rate). A slice translates like the figure it belongs to (D16.4): assets and liabilities at closing, income and
 * expense at the average, equity at its historical rates; each slice is rounded once, HALF_UP, in minor units.
 *
 * Matching is per pair of members and class, in the presentation currency: side A is what A holds against B, side B what B
 * holds against A, and the difference is A + B (zero when they mirror each other). Its cause: `missing_counterpart` when one
 * side holds nothing, `fx` when the amounts agree in every transaction currency (so only rates differ), else `timing`.
 *
 * Eliminations: "matched balances and intragroup income and expense eliminate; intragroup dividends eliminate against the
 * receiving company's income". The matched amount is the smaller side when the sides are opposite, and nothing otherwise;
 * it eliminates from both sides, so every entry balances on its own. The difference is not eliminated: it stays in the
 * consolidated figures, shown in the report, and above the group's tolerance it blocks approval until a second person
 * accepts it (the Action). Within a side, lines opposite to the side's own sign (a member owing and being owed by the same
 * counterparty) eliminate in full first, then the others in account order.
 *
 * A loan designated part of the net investment in a foreign operation: the exchange difference its member booked in profit
 * or loss over the window moves to the translation reserve (IAS 21.32, D16.7), translated at the average like the profit or
 * loss it leaves.
 *
 * @phpstan-type IcSlice array{class: string, counterparty: string, account_id: string, net: int, currencies: array<string, int>, moves: list<array{net: int, rate: string|null}>}
 * @phpstan-type IcMatch array{member_a: string, member_b: string, class: string, side_a: int, side_b: int, difference: int, matched: int, cause: string|null, exceeds_tolerance: bool, detail: array{currencies: array<string, int>}}
 * @phpstan-type Proposed array{kind: string, member_id: string|null, memo: string, lines: list<array{account_id: string, member_id: string|null, net: int}>}
 */
final class Intercompany
{
    public const string BALANCE = 'balance';

    public const string LOAN = 'loan';

    public const string INCOME_EXPENSE = 'income_expense';

    public const string DIVIDEND = 'dividend';

    public const array CLASSES = [self::BALANCE, self::LOAN, self::INCOME_EXPENSE, self::DIVIDEND];

    /** Group account types a class may hold: a dividend is income in the receiver and equity in the payer. */
    public const array TYPES = [
        self::BALANCE => ['asset', 'liability'], self::LOAN => ['asset', 'liability'],
        self::INCOME_EXPENSE => ['income', 'expense'], self::DIVIDEND => ['income', 'equity'],
    ];

    private const array LABELS = [
        self::BALANCE => 'receivables and payables', self::LOAN => 'loans',
        self::INCOME_EXPENSE => 'income and expense', self::DIVIDEND => 'dividends',
    ];

    /**
     * @return array{matches: list<IcMatch>, proposed: list<Proposed>}
     */
    public function run(RunInput $in, string $trAccount): array
    {
        $ids = array_map(fn (MemberInput $m): string => $m->id, $in->members);
        /** @var array<string, array<string, array<string, int>>> $sides key → member → group account → translated */
        $sides = [];
        /** @var array<string, array<string, int>> $currencies key → currency → amount */
        $currencies = [];
        $proposed = [];
        foreach ($in->members as $m) {
            foreach ($m->intercompany as $s) {
                if ($s['counterparty'] === $m->id || ! in_array($s['counterparty'], $ids, true)) {
                    continue;
                }
                [$a, $b] = strcmp($m->id, $s['counterparty']) < 0 ? [$m->id, $s['counterparty']] : [$s['counterparty'], $m->id];
                $key = "{$a}|{$b}|{$s['class']}";
                $sides[$key][$a] ??= [];
                $sides[$key][$b] ??= [];
                $sides[$key][$m->id][$s['account_id']] = ($sides[$key][$m->id][$s['account_id']] ?? 0) + $this->translate($m, $in, $s);
                foreach ($s['currencies'] as $cur => $amount) {
                    $currencies[$key][$cur] = ($currencies[$key][$cur] ?? 0) + $amount;
                }
            }
        }
        ksort($sides);

        $matches = [];
        foreach ($sides as $key => $byMember) {
            [$a, $b, $class] = explode('|', $key);
            $sideA = array_sum($byMember[$a]);
            $sideB = array_sum($byMember[$b]);
            $difference = $sideA + $sideB;
            $matched = ($sideA > 0 && $sideB < 0) || ($sideA < 0 && $sideB > 0) ? min(abs($sideA), abs($sideB)) : 0;
            $cur = $currencies[$key] ?? [];
            ksort($cur);
            $matches[] = [
                'member_a' => $a, 'member_b' => $b, 'class' => $class, 'side_a' => $sideA, 'side_b' => $sideB,
                'difference' => $difference, 'matched' => $matched, 'cause' => $this->cause($sideA, $sideB, $difference, $cur),
                'exceeds_tolerance' => abs($difference) > $in->icTolerance, 'detail' => ['currencies' => $cur],
            ];
            if ($matched > 0) {
                $proposed[] = [
                    'kind' => 'elimination', 'member_id' => null, 'memo' => 'Elimination of intragroup '.(self::LABELS[$class] ?? $class),
                    'lines' => [...$this->eliminate($byMember[$a], $sideA, $matched, $a), ...$this->eliminate($byMember[$b], $sideB, $matched, $b)],
                ];
            }
        }

        foreach ($in->members as $m) {
            $move = 0;
            $lines = [];
            foreach ($m->netInvestment as $row) {
                $t = $m->currency === $in->currency ? $row['net'] : Rounding::translate($row['net'], $this->rate($m->averageRate, $m->id), $m->scale, $in->scale);
                if ($t !== 0) {
                    $lines[] = ['account_id' => $row['account_id'], 'member_id' => $m->id, 'net' => -$t];
                    $move += $t;
                }
            }
            if ($move !== 0 || $lines !== []) {
                $lines[] = ['account_id' => $trAccount, 'member_id' => $m->id, 'net' => $move];
                $proposed[] = ['kind' => 'net_investment', 'member_id' => $m->id, 'memo' => 'Exchange difference on a loan forming part of the net investment, to the translation reserve (IAS 21.32)', 'lines' => array_values(array_filter($lines, fn (array $l): bool => $l['net'] !== 0))];
            }
        }

        return ['matches' => $matches, 'proposed' => array_values(array_filter($proposed, fn (array $p): bool => count($p['lines']) >= 2))];
    }

    /** @param IcSlice $s */
    private function translate(MemberInput $m, RunInput $in, array $s): int
    {
        $type = $in->accounts[$s['account_id']]['type'] ?? 'asset';
        if ($type === 'equity') {
            if ($m->currency === $in->currency) {
                return $s['net'];
            }
            $total = 0;
            foreach ($s['moves'] as $mv) {
                $total += Rounding::translate($mv['net'], $this->rate($mv['rate'], $m->id), $m->scale, $in->scale);
            }

            return $total;
        }
        if ($m->currency === $in->currency) {
            return $s['net'];
        }
        $rate = GroupRoles::isProfitAndLoss($type) ? $m->averageRate : $m->closingRate;

        return Rounding::translate($s['net'], $this->rate($rate, $m->id), $m->scale, $in->scale);
    }

    /**
     * Lines eliminating $matched from one side (net = the reverse of what they take out).
     *
     * @param  array<string, int>  $accounts  group account → translated amount
     * @return list<array{account_id: string, member_id: string, net: int}>
     */
    private function eliminate(array $accounts, int $side, int $matched, string $memberId): array
    {
        $sign = $side <=> 0;
        ksort($accounts);
        $out = [];
        $budget = $matched;
        foreach ($accounts as $acc => $v) {
            if ($v * $sign < 0) {
                $out[] = ['account_id' => (string) $acc, 'member_id' => $memberId, 'net' => -$v];
                $budget += abs($v);
            }
        }
        foreach ($accounts as $acc => $v) {
            if ($v * $sign > 0 && $budget > 0) {
                $take = min(abs($v), $budget);
                $out[] = ['account_id' => (string) $acc, 'member_id' => $memberId, 'net' => -$sign * $take];
                $budget -= $take;
            }
        }

        return $out;
    }

    /** @param array<string, int> $currencies */
    private function cause(int $sideA, int $sideB, int $difference, array $currencies): ?string
    {
        if ($difference === 0) {
            return null;
        }
        if ($sideA === 0 || $sideB === 0) {
            return 'missing_counterpart';
        }

        return array_filter($currencies, fn (int $v): bool => $v !== 0) === [] ? 'fx' : 'timing';
    }

    private function rate(?string $rate, string $memberId): string
    {
        return $rate ?? throw DomainRuleViolation::single('fx_rate_missing', "No rate for an intragroup item of member {$memberId}.");
    }
}
