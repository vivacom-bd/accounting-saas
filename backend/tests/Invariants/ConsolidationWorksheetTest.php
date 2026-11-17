<?php

declare(strict_types=1);

use App\Domain\Consolidation\ConsolidationCalculator;
use App\Domain\Consolidation\MemberInput;
use App\Domain\Consolidation\Rounding;
use App\Domain\Consolidation\RunInput;

/*
 * D16.9: the worksheet balances for every run. Property-style over seeded random ledgers: several members in random
 * currencies and scales, random balanced trial balances, equity movements at random rates, chained over several runs so
 * each run starts from the closing position the previous one stored. Whatever the figures, every translated column and
 * the consolidated total sum to exactly zero, and the reserve carried forward is the one the next run starts from.
 */

it('balances every translated column and the consolidated worksheet on random ledgers, run after run', function (int $seed): void {
    mt_srand($seed);
    $accounts = [
        'cash' => ['type' => 'asset', 'role' => null], 'ppe' => ['type' => 'asset', 'role' => null], 'pay' => ['type' => 'liability', 'role' => null],
        'cap' => ['type' => 'equity', 'role' => null], 'div' => ['type' => 'equity', 'role' => null],
        're' => ['type' => 'equity', 'role' => 'retained_earnings'], 'tr' => ['type' => 'equity', 'role' => 'translation_reserve'],
        'rev' => ['type' => 'income', 'role' => null], 'exp' => ['type' => 'expense', 'role' => null],
    ];
    $rate = fn (): string => sprintf('%d.%08d', mt_rand(0, 3), mt_rand(1, 99999999));
    $currencies = [['USD', 2], ['KES', 2], ['JPY', 0], ['KWD', 3]];

    $members = [];
    for ($i = 0; $i < 4; $i++) {
        [$ccy, $scale] = $i === 0 ? ['USD', 2] : $currencies[mt_rand(0, 3)];
        $cap = -mt_rand(0, 10_000_000);
        $members[] = ['id' => "M{$i}", 'ccy' => $ccy, 'scale' => $scale, 'local' => ['cap' => $cap, 'cash' => -$cap], 'pl' => [],
            'opening' => ['local' => ['cap' => $cap, 're' => 0], 'translated' => ['cap' => Rounding::translate($cap, $rate(), $scale, 2), 're' => 0], 'tr' => 0]];
    }

    $from = new DateTimeImmutable('2026-01-01');
    for ($run = 0; $run < 4; $run++) {
        $to = $from->modify('last day of this month');
        $inputs = [];
        foreach ($members as &$m) {
            // A month of random activity, every journal balanced.
            $bs = $m['local'];
            $pl = [];
            $moves = [];
            for ($k = mt_rand(0, 6); $k > 0; $k--) {
                $amount = mt_rand(1, 5_000_000);
                switch (mt_rand(0, 4)) {
                    case 0: $pl['rev'] = ($pl['rev'] ?? 0) - $amount;
                        $bs['cash'] = ($bs['cash'] ?? 0) + $amount;
                        break;
                    case 1: $pl['exp'] = ($pl['exp'] ?? 0) + $amount;
                        $bs['pay'] = ($bs['pay'] ?? 0) - $amount;
                        break;
                    case 2: $bs['ppe'] = ($bs['ppe'] ?? 0) + $amount;
                        $bs['cash'] = ($bs['cash'] ?? 0) - $amount;
                        break;
                    case 3: $bs['div'] = ($bs['div'] ?? 0) + $amount;
                        $bs['cash'] = ($bs['cash'] ?? 0) - $amount;
                        $moves[] = ['account_id' => 'div', 'date' => $from->modify('+'.mt_rand(0, 27).' days')->format('Y-m-d'), 'net' => $amount, 'rate' => $m['ccy'] === 'USD' ? null : $rate()];
                        break;
                    default: $bs['cap'] = ($bs['cap'] ?? 0) - $amount;
                        $bs['cash'] = ($bs['cash'] ?? 0) + $amount;
                        $moves[] = ['account_id' => 'cap', 'date' => $from->modify('+'.mt_rand(0, 27).' days')->format('Y-m-d'), 'net' => -$amount, 'rate' => $m['ccy'] === 'USD' ? null : $rate()];
                }
            }
            // Balance sheet balances carry earlier profit in retained earnings; the calculator derives it as the balancing figure.
            $inputs[] = new MemberInput($m['id'], $m['id'] === 'M0', $m['ccy'], $m['scale'], $rate(), $rate(), array_filter($bs), array_filter($pl), $moves, $m['ccy'] === 'USD' ? null : $m['opening']);
            $m['bs'] = $bs;
        }
        unset($m);

        $result = (new ConsolidationCalculator)->run(new RunInput('USD', 2, $from->format('Y-m-d'), $to->format('Y-m-d'), $accounts, $inputs));

        foreach ($result->translated as $id => $column) {
            expect(array_sum($column))->toBe(0, "seed {$seed} run {$run}: member {$id} does not balance");
            expect(array_sum($result->local[$id]))->toBe(0);
        }
        expect($result->isBalanced())->toBeTrue("seed {$seed} run {$run}: worksheet does not balance");

        foreach ($members as &$m) {
            $state = $result->states[$m['id']];
            $m['opening'] = $state;
            $m['local'] = $m['bs'];
        }
        unset($m);
        $from = $to->modify('+1 day');
    }
})->with(range(1, 25));

/*
 * Plan 3c.29c: the same over random intragroup trade. Members sell to each other, the buyer sometimes booking a different
 * amount (or nothing), in a random transaction currency; some members carry an exchange difference on a net-investment
 * loan. Whatever the figures, run after run: every entry balances on its own; both sides of every pair eliminate in full;
 * the difference of a balance takes only its movement to profit or loss, so what the intercompany differences account has
 * taken over the runs equals the difference standing now; and the consolidated worksheet still sums to zero.
 */
it('keeps the worksheet balanced, eliminates both sides in full and takes only the movement of a balance difference to profit or loss, on random intragroup trade', function (int $seed): void {
    mt_srand($seed);
    $accounts = [
        'cash' => ['type' => 'asset', 'role' => null], 'icr' => ['type' => 'asset', 'role' => null], 'icp' => ['type' => 'liability', 'role' => null],
        're' => ['type' => 'equity', 'role' => 'retained_earnings'], 'tr' => ['type' => 'equity', 'role' => 'translation_reserve'],
        'icrev' => ['type' => 'income', 'role' => null], 'icexp' => ['type' => 'expense', 'role' => null], 'fx' => ['type' => 'income', 'role' => null],
        'icd' => ['type' => 'expense', 'role' => 'ic_difference'],
    ];
    $rate = fn (): string => sprintf('%d.%08d', mt_rand(0, 3), mt_rand(1, 99999999));
    $currencies = [['USD', 2], ['KES', 2], ['JPY', 0], ['KWD', 3]];
    $prior = [];
    $taken = [];
    $members = [];
    for ($i = 0; $i < 4; $i++) {
        [$ccy, $scale] = $i === 0 ? ['USD', 2] : $currencies[mt_rand(0, 3)];
        $members["M{$i}"] = ['ccy' => $ccy, 'scale' => $scale, 'bs' => ['cash' => 0], 'ic' => [], 'opening' => ['local' => ['re' => 0], 'translated' => ['re' => 0], 'tr' => 0]];
    }
    $ids = array_keys($members);

    $from = new DateTimeImmutable('2026-01-01');
    for ($run = 0; $run < 3; $run++) {
        $to = $from->modify('last day of this month');
        $pl = array_fill_keys($ids, []);
        $icPl = array_fill_keys($ids, []);
        $ni = array_fill_keys($ids, []);
        for ($k = mt_rand(1, 8); $k > 0; $k--) {
            [$seller, $buyer] = [$ids[mt_rand(0, 3)], $ids[mt_rand(0, 3)]];
            if ($seller === $buyer) {
                continue;
            }
            $amount = mt_rand(1, 3_000_000);
            $booked = [$amount, $amount, $amount - mt_rand(0, 500), 0][mt_rand(0, 3)];
            $txCcy = $currencies[mt_rand(0, 3)][0];
            foreach ([[$seller, $buyer, 'icr', 'icrev', $amount], [$buyer, $seller, 'icp', 'icexp', -$booked]] as [$me, $other, $bsAcc, $plAcc, $net]) {
                if ($net === 0) {
                    continue;
                }
                $key = "{$other}|{$bsAcc}";
                $members[$me]['bs'][$bsAcc] = ($members[$me]['bs'][$bsAcc] ?? 0) + $net;
                $pl[$me][$plAcc] = ($pl[$me][$plAcc] ?? 0) - $net;
                $members[$me]['ic'][$key] ??= ['net' => 0, 'ccy' => []];
                $members[$me]['ic'][$key]['net'] += $net;
                $members[$me]['ic'][$key]['ccy'][$txCcy] = ($members[$me]['ic'][$key]['ccy'][$txCcy] ?? 0) + $net;
                $icPl[$me][] = ['class' => 'income_expense', 'counterparty' => $other, 'account_id' => $plAcc, 'net' => -$net, 'currencies' => [$txCcy => -$net], 'moves' => []];
            }
        }
        foreach ($ids as $id) {
            if (mt_rand(0, 2) === 0) {
                $fx = mt_rand(-200_000, 200_000);
                $pl[$id]['fx'] = ($pl[$id]['fx'] ?? 0) + $fx;
                $ni[$id][] = ['account_id' => 'fx', 'net' => $fx];
            }
        }

        $inputs = [];
        foreach ($members as $id => $m) {
            $slices = $icPl[$id];
            foreach ($m['ic'] as $key => $v) {
                [$other, $acc] = explode('|', $key);
                $slices[] = ['class' => 'balance', 'counterparty' => $other, 'account_id' => $acc, 'net' => $v['net'], 'currencies' => $v['ccy'], 'moves' => []];
            }
            // Cash takes whatever balances the member's books, so its retained earnings is exactly the opening one (no
            // equity movement in the window).
            $bs = $m['bs'];
            $bs['cash'] -= array_sum($bs) + array_sum($pl[$id]) + $m['opening']['local']['re'];
            $inputs[] = new MemberInput($id, $id === 'M0', $m['ccy'], $m['scale'], $rate(), $rate(), array_filter($bs), array_filter($pl[$id]), [], $m['ccy'] === 'USD' ? null : $m['opening'], $slices, $ni[$id]);
            $members[$id]['bs'] = $bs;
        }

        $result = (new ConsolidationCalculator)->run(new RunInput('USD', 2, $from->format('Y-m-d'), $to->format('Y-m-d'), $accounts, $inputs, [], mt_rand(0, 1000), $prior));
        $why = "seed {$seed} run {$run}";

        expect($result->isBalanced())->toBeTrue("{$why}: worksheet does not balance");
        foreach ($result->proposed as $p) {
            expect(array_sum(array_column($p['lines'], 'net')))->toBe(0, "{$why}: a {$p['kind']} entry does not balance");
        }
        // Both sides in full: the eliminations take out of each member exactly what it holds against the others.
        $held = [];
        $icd = 0;
        $re = 0;
        $nextPrior = [];
        foreach ($result->matches as $m) {
            $held[$m['member_a']] = ($held[$m['member_a']] ?? 0) + $m['side_a'];
            $held[$m['member_b']] = ($held[$m['member_b']] ?? 0) + $m['side_b'];
            $key = "{$m['member_a']}|{$m['member_b']}|{$m['class']}";
            expect($m['difference'] + $m['to_reserve'])->toBe($m['side_a'] + $m['side_b'], "{$why}: {$key}");
            $icd += $m['difference'] - $m['recognised_before'];
            $re += $m['recognised_before'];
            if ($m['class'] === 'balance') {
                expect($m['recognised_before'])->toBe($prior[$key] ?? 0, "{$why}: {$key} recognised before");
                $taken[$key] = ($taken[$key] ?? 0) + $m['difference'] - $m['recognised_before'];
                expect($taken[$key])->toBe($m['difference'], "{$why}: {$key} taken to profit or loss over the runs");
                $nextPrior[$key] = $m['difference'];
            }
        }
        foreach ($held as $member => $amount) {
            $out = 0;
            foreach ($result->proposed as $p) {
                if ($p['kind'] === 'elimination') {
                    foreach ($p['lines'] as $l) {
                        $out += $l['member_id'] === $member ? $l['net'] : 0;
                    }
                }
            }
            expect($out)->toBe(-$amount, "{$why}: member {$member} not eliminated in full");
        }
        $groupLines = fn (string $account): int => array_sum(array_map(fn (array $c): int => $c['source'] === 'elimination' && $c['account_id'] === $account ? $c['net'] : 0, $result->cells));
        expect($groupLines('icd'))->toBe($icd, "{$why}: intercompany differences")
            ->and($groupLines('re'))->toBe($re, "{$why}: differences recognised before, in retained earnings");
        $prior = $nextPrior;

        foreach ($ids as $id) {
            $members[$id]['opening'] = $result->states[$id];
        }
        $from = $to->modify('+1 day');
    }
})->with(range(1, 25));
