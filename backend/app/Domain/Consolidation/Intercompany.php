<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

use App\Domain\Shared\DomainRuleViolation;

/**
 * Intercompany matching and eliminations (13 §23 D16.7, plan 3c.29c, owner's answers of 2026-11-17), pure: plain data in,
 * plain data out.
 *
 * Each member brings slices of intragroup items: a class, the counterparty member, the group account and its local amount
 * (balance sheet at the end, profit and loss over the member's window, a dividend paid out of equity per day with that
 * day's rate). A slice translates like the figure it belongs to (D16.4): assets and liabilities at closing, income and
 * expense at the average, equity at its historical rates; each slice is rounded once, HALF_UP, in minor units.
 *
 * Matching is per pair of members and class, in the presentation currency: side A is what A holds against B, side B what B
 * holds against A. Their sum is zero when they mirror each other. Its cause: `missing_counterpart` when one side holds
 * nothing, `fx` when the amounts agree in every transaction currency (so only rates differ), else `timing`.
 *
 * Eliminations: both sides of a pair eliminate in full, every line, so nothing intragroup is left. The entry balances with
 *  - for a dividend, its FX part to the translation reserve: income translated at the average instead of at the rate of
 *    its own day (and, when the amounts agree in every transaction currency, whatever else is left, which can then only be
 *    rates). What remains is the intercompany difference;
 *  - the intercompany difference to the group's `ic_difference` account (profit or loss). For a balance or a loan only its
 *    movement since the previous approved run is profit or loss: the difference that run recorded was recognised then and
 *    sits in retained earnings now. Income, expense and dividends are flows of the period, so their whole difference is
 *    profit or loss. A difference above the group's tolerance blocks approval until a second person accepts it (the Action).
 *
 * A loan designated part of the net investment in a foreign operation: the exchange differences its member booked in
 * profit or loss over the window, realised and unrealised, move to the translation reserve (IAS 21.32, D16.7), translated at
 * the average like the profit or loss they leave.
 *
 * @phpstan-type IcSlice array{class: string, counterparty: string, account_id: string, net: int, currencies: array<string, int>, moves: list<array{net: int, rate: string|null}>}
 * @phpstan-type IcMatch array{member_a: string, member_b: string, class: string, side_a: int, side_b: int, difference: int, to_reserve: int, recognised_before: int, matched: int, cause: string|null, exceeds_tolerance: bool, detail: array{currencies: array<string, int>}}
 * @phpstan-type Proposed array{kind: string, member_id: string|null, memo: string, lines: list<array{account_id: string, member_id: string|null, net: int}>}
 */
final class Intercompany
{
    public const string BALANCE = 'balance';

    public const string LOAN = 'loan';

    public const string INCOME_EXPENSE = 'income_expense';

    public const string DIVIDEND = 'dividend';

    public const array CLASSES = [self::BALANCE, self::LOAN, self::INCOME_EXPENSE, self::DIVIDEND];

    /** Classes whose difference is a position carried from run to run, so only its movement is profit or loss. */
    public const array POSITIONS = [self::BALANCE, self::LOAN];

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
    public function run(RunInput $in, string $reAccount, string $trAccount): array
    {
        $ids = array_map(fn (MemberInput $m): string => $m->id, $in->members);
        /** @var array<string, array<string, array<string, int>>> $sides key → member → group account → translated */
        $sides = [];
        /** @var array<string, array<string, int>> $currencies key → currency → amount */
        $currencies = [];
        /** @var array<string, int> $fx key → FX part of a dividend */
        $fx = [];
        foreach ($in->members as $m) {
            foreach ($m->intercompany as $s) {
                if ($s['counterparty'] === $m->id || ! in_array($s['counterparty'], $ids, true)) {
                    continue;
                }
                $key = $this->key($m->id, $s['counterparty'], $s['class']);
                [$a, $b] = explode('|', $key);
                $sides[$key][$a] ??= [];
                $sides[$key][$b] ??= [];
                $t = $this->translate($m, $in, $s);
                $sides[$key][$m->id][$s['account_id']] = ($sides[$key][$m->id][$s['account_id']] ?? 0) + $t;
                foreach ($s['currencies'] as $cur => $amount) {
                    $currencies[$key][$cur] = ($currencies[$key][$cur] ?? 0) + $amount;
                }
                if ($s['class'] === self::DIVIDEND && $s['moves'] !== []) {
                    $fx[$key] = ($fx[$key] ?? 0) + $t - $this->atDayRates($m, $in, $s);
                }
            }
        }
        // A balance or loan difference recorded by the previous run, with no item left now, still has to be taken back.
        foreach ($in->icPrior as $key => $amount) {
            [$a, $b, $class] = explode('|', (string) $key) + ['', '', ''];
            if ($amount !== 0 && in_array($a, $ids, true) && in_array($b, $ids, true) && in_array($class, self::POSITIONS, true)) {
                $sides[(string) $key][$a] ??= [];
                $sides[(string) $key][$b] ??= [];
            }
        }
        ksort($sides);

        $matches = [];
        $proposed = [];
        $icAccount = null;
        foreach ($sides as $key => $byMember) {
            [$a, $b, $class] = explode('|', $key);
            $sideA = array_sum($byMember[$a]);
            $sideB = array_sum($byMember[$b]);
            $total = $sideA + $sideB;
            $cur = $currencies[$key] ?? [];
            ksort($cur);
            $agree = array_filter($cur, fn (int $v): bool => $v !== 0) === [];
            $toReserve = $class === self::DIVIDEND ? ($agree ? $total : ($fx[$key] ?? 0)) : 0;
            $difference = $total - $toReserve;
            $before = in_array($class, self::POSITIONS, true) ? ($in->icPrior[$key] ?? 0) : 0;
            $matches[] = [
                'member_a' => $a, 'member_b' => $b, 'class' => $class, 'side_a' => $sideA, 'side_b' => $sideB,
                'difference' => $difference, 'to_reserve' => $toReserve, 'recognised_before' => $before,
                'matched' => ($sideA > 0 && $sideB < 0) || ($sideA < 0 && $sideB > 0) ? min(abs($sideA), abs($sideB)) : 0,
                'cause' => $this->cause($sideA, $sideB, $difference, $agree),
                'exceeds_tolerance' => abs($difference) > $in->icTolerance, 'detail' => ['currencies' => $cur],
            ];

            $lines = [...$this->reverse($byMember[$a], $a), ...$this->reverse($byMember[$b], $b)];
            if ($toReserve !== 0) {
                $lines[] = ['account_id' => $trAccount, 'member_id' => null, 'net' => $toReserve];
            }
            if ($difference - $before !== 0) {
                $icAccount ??= $in->roleAccount(GroupRoles::IC_DIFFERENCE) ?? throw DomainRuleViolation::single('group_role_missing', 'The group has no intercompany differences account.');
                $lines[] = ['account_id' => $icAccount, 'member_id' => null, 'net' => $difference - $before];
            }
            if ($before !== 0) {
                $lines[] = ['account_id' => $reAccount, 'member_id' => null, 'net' => $before];
            }
            if (count($lines) >= 2) {
                $proposed[] = ['kind' => 'elimination', 'member_id' => null, 'memo' => 'Elimination of intragroup '.(self::LABELS[$class] ?? $class), 'lines' => $lines];
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
            if ($lines !== []) {
                if ($move !== 0) {
                    $lines[] = ['account_id' => $trAccount, 'member_id' => $m->id, 'net' => $move];
                }
                if (count($lines) >= 2) {
                    $proposed[] = ['kind' => 'net_investment', 'member_id' => $m->id, 'memo' => 'Exchange differences on a loan forming part of the net investment, to the translation reserve (IAS 21.32)', 'lines' => $lines];
                }
            }
        }

        return ['matches' => $matches, 'proposed' => $proposed];
    }

    /** The pair key, members in order: "a|b|class". */
    public function key(string $member, string $counterparty, string $class): string
    {
        return strcmp($member, $counterparty) < 0 ? "{$member}|{$counterparty}|{$class}" : "{$counterparty}|{$member}|{$class}";
    }

    /** @param IcSlice $s */
    private function translate(MemberInput $m, RunInput $in, array $s): int
    {
        $type = $in->accounts[$s['account_id']]['type'] ?? 'asset';
        if ($m->currency === $in->currency) {
            return $s['net'];
        }
        if ($type === 'equity' && $s['moves'] !== []) {
            return $this->atDayRates($m, $in, $s);
        }
        $rate = GroupRoles::isProfitAndLoss($type) ? $m->averageRate : $m->closingRate;

        return Rounding::translate($s['net'], $this->rate($rate, $m->id), $m->scale, $in->scale);
    }

    /**
     * The slice at the rate of each of its days (the rate of a dividend's own day).
     *
     * @param  IcSlice  $s
     */
    private function atDayRates(MemberInput $m, RunInput $in, array $s): int
    {
        if ($m->currency === $in->currency) {
            return $s['net'];
        }
        $total = 0;
        foreach ($s['moves'] as $mv) {
            $total += Rounding::translate($mv['net'], $this->rate($mv['rate'], $m->id), $m->scale, $in->scale);
        }

        return $total;
    }

    /**
     * Lines taking one side out in full, in account order.
     *
     * @param  array<string, int>  $accounts  group account → translated amount
     * @return list<array{account_id: string, member_id: string, net: int}>
     */
    private function reverse(array $accounts, string $memberId): array
    {
        ksort($accounts);
        $out = [];
        foreach ($accounts as $acc => $v) {
            if ($v !== 0) {
                $out[] = ['account_id' => (string) $acc, 'member_id' => $memberId, 'net' => -$v];
            }
        }

        return $out;
    }

    private function cause(int $sideA, int $sideB, int $difference, bool $agree): ?string
    {
        if ($difference === 0) {
            return null;
        }
        if ($sideA === 0 || $sideB === 0) {
            return 'missing_counterpart';
        }

        return $agree ? 'fx' : 'timing';
    }

    private function rate(?string $rate, string $memberId): string
    {
        return $rate ?? throw DomainRuleViolation::single('fx_rate_missing', "No rate for an intragroup item of member {$memberId}.");
    }
}
