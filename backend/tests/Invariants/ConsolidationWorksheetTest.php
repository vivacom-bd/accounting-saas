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
