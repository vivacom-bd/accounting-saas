<?php

declare(strict_types=1);

use App\Domain\Consolidation\ConsolidationCalculator;
use App\Domain\Consolidation\MemberInput;
use App\Domain\Consolidation\Rounding;
use App\Domain\Consolidation\RunInput;
use App\Domain\Consolidation\RunResult;
use App\Domain\Shared\DomainRuleViolation;

/*
 * Worked examples, computed by hand in the comments (amounts in minor units, so 100000 = 1,000.00). Net = debit − credit,
 * so a credit is negative. The group presents in USD; "K" is a subsidiary keeping its books in KES (D16.4).
 */

/** @return array<string, array{type: string, role: string|null}> */
function chart(): array
{
    return [
        'cash' => ['type' => 'asset', 'role' => null], 'inv' => ['type' => 'asset', 'role' => null], 'pay' => ['type' => 'liability', 'role' => null],
        'cap' => ['type' => 'equity', 'role' => null], 'div' => ['type' => 'equity', 'role' => null],
        're' => ['type' => 'equity', 'role' => 'retained_earnings'], 'tr' => ['type' => 'equity', 'role' => 'translation_reserve'],
        'rev' => ['type' => 'income', 'role' => null], 'exp' => ['type' => 'expense', 'role' => null],
    ];
}

/**
 * @param  array<string, int>  $bs
 * @param  array<string, int>  $pl
 * @param  list<array{account_id: string, date: string, net: int, rate: string|null}>  $moves
 * @param  array{local: array<string, int>, translated: array<string, int>, tr: int}|null  $opening
 */
function kes(array $bs, array $pl, array $moves = [], ?array $opening = null, ?string $closing = '0.0075', ?string $average = '0.0078'): MemberInput
{
    return new MemberInput('K', false, 'KES', 2, $closing, $average, $bs, $pl, $moves, $opening);
}

function usdParent(): MemberInput
{
    return new MemberInput('P', true, 'USD', 2, null, null, ['inv' => 800000, 'cash' => 200000, 'cap' => -1000000], ['exp' => 1000, 'rev' => -1000]);
}

function runOf(MemberInput ...$members): RunResult
{
    return (new ConsolidationCalculator)->run(new RunInput('USD', 2, '2026-01-01', '2026-03-31', chart(), array_values($members)));
}

/** K was acquired on 31 Dec 2025 at 0.0080 with capital of KES 1,000,000.00 and no retained earnings. */
function kesOpening(): array
{
    return ['local' => ['cap' => -100000000, 're' => 0], 'translated' => ['cap' => -800000, 're' => 0], 'tr' => 0];
}

it('translates a KES subsidiary: closing for assets and liabilities, average for income and expense, historical for equity (D16.4)', function (): void {
    // K in the quarter: revenue 300,000.00, expense 100,000.00, a dividend of 50,000.00 declared on 15 Feb (rate 0.0077).
    // Cash = 1,000,000 + 300,000 − 100,000 − 50,000 = 1,150,000.00 KES; payables 0.
    $k = kes(
        ['cash' => 115000000, 'cap' => -100000000, 'div' => 5000000],
        ['rev' => -30000000, 'exp' => 10000000],
        [['account_id' => 'div', 'date' => '2026-02-15', 'net' => 5000000, 'rate' => '0.0077']],
        kesOpening(),
    );
    $r = runOf(usdParent(), $k);

    // Closing: cash 115,000,000 × 0.0075 = 862,500. Average: revenue −234,000, expense 78,000.
    // Historical: capital at the acquisition rate −800,000; the dividend at the rate of its own date 5,000,000 × 0.0077 = 38,500.
    // Retained earnings: 0 locally and at the opening. Reserve (the balancing line) = −(862,500 − 800,000 + 38,500 − 234,000 + 78,000) = 55,000.
    // A debit: KES weakened from 0.0080 to 0.0075, so the net assets lost value in USD.
    expect($r->translated['K'])->toBe(['cash' => 862500, 'cap' => -800000, 'div' => 38500, 'rev' => -234000, 'exp' => 78000, 'tr' => 55000])
        ->and(array_sum($r->translated['K']))->toBe(0)
        ->and($r->states['K']['tr'])->toBe(55000)
        ->and($r->summary['translation_difference'])->toBe(55000)
        ->and($r->isBalanced())->toBeTrue();
});

it('gives a credit reserve when the functional currency strengthens', function (): void {
    // Same opening; no activity; the rate rises to 0.0090: net assets 1,000,000.00 KES = 900,000 against 800,000 historical.
    $k = kes(['cash' => 100000000, 'cap' => -100000000], [], [], kesOpening(), '0.0090', '0.0085');
    $r = runOf(usdParent(), $k);

    expect($r->translated['K'])->toBe(['cash' => 900000, 'cap' => -800000, 'tr' => -100000])
        ->and($r->consolidated['tr'])->toBe(-100000)
        ->and($r->isBalanced())->toBeTrue();
});

it('carries the reserve and historical equity forward: the next run moves the reserve only by the new difference', function (): void {
    $first = runOf(usdParent(), kes(['cash' => 100000000, 'cap' => -100000000], [], [], kesOpening(), '0.0090', '0.0085'));
    // The next quarter starts from the stored closing position; the rate falls back to 0.0085, no activity.
    $next = (new ConsolidationCalculator)->run(new RunInput('USD', 2, '2026-04-01', '2026-06-30', chart(), [
        usdParent(), kes(['cash' => 100000000, 'cap' => -100000000], [], [], $first->states['K'], '0.0085', '0.0087'),
    ]));

    // Cash 850,000; capital still −800,000 (historical); cumulative reserve −50,000; this period's difference +50,000.
    expect($next->translated['K'])->toBe(['cash' => 850000, 'cap' => -800000, 'tr' => -50000])
        ->and($next->summary['translation_difference'])->toBe(50000);
});

it('rolls profit into translated retained earnings at the average rate for the next run', function (): void {
    $k = kes(['cash' => 120000000, 'cap' => -100000000], ['rev' => -30000000, 'exp' => 10000000], [], kesOpening());
    $r = runOf(usdParent(), $k);

    // Profit 200,000.00 KES at 0.0078 = 156,000 USD, carried into retained earnings: local −20,000,000, translated −156,000.
    expect($r->states['K']['local'])->toBe(['cap' => -100000000, 're' => -20000000])
        ->and($r->states['K']['translated'])->toBe(['cap' => -800000, 're' => -156000]);
});

it('rounds each translated figure once, half up, and puts the residual in the translation reserve', function (): void {
    // Cash 333 × 0.0075 = 2.4975 → 2; payables −111 × 0.0075 = −0.8325 → −1; capital −222 at 0.0080 = −1.776 → −2.
    // Exact: net assets 222 × 0.0075 = 1.665 against −1.776 → reserve 0.111; in minor units the reserve takes 1 so the column balances.
    $k = kes(['cash' => 333, 'pay' => -111, 'cap' => -222], [], [], ['local' => ['cap' => -222, 're' => 0], 'translated' => ['cap' => -2, 're' => 0], 'tr' => 0]);
    $r = runOf(usdParent(), $k);

    expect($r->translated['K'])->toBe(['cash' => 2, 'pay' => -1, 'cap' => -2, 'tr' => 1])
        ->and(array_sum($r->translated['K']))->toBe(0)
        ->and(Rounding::translate(5, '0.5', 2, 2))->toBe(3)      // 2.5 → 3 (half up)
        ->and(Rounding::translate(-5, '0.5', 2, 2))->toBe(-3);   // −2.5 → −3 (halves away from zero)
});

it('translates a member with no balances and no activity to nothing, with no reserve line', function (): void {
    $empty = kes([], [], [], ['local' => [], 'translated' => [], 'tr' => 0]);
    $r = runOf(usdParent(), $empty);

    expect($r->translated['K'])->toBe([])->and($r->states['K']['tr'])->toBe(0)->and($r->isBalanced())->toBeTrue();
});

it('passes a member in the presentation currency through unchanged, with no reserve', function (): void {
    $s = new MemberInput('S', false, 'USD', 2, null, null, ['cash' => 120000, 'cap' => -100000, 'div' => 5000], ['rev' => -50000, 'exp' => 25000],
        [['account_id' => 'div', 'date' => '2026-02-15', 'net' => 5000, 'rate' => null]]);
    $r = runOf(usdParent(), $s);

    // Retained earnings = −(120,000 − 100,000 + 5,000 − 50,000 + 25,000) = 0, filtered out of the column.
    expect($r->translated['S'])->toBe($r->local['S'])
        ->and($r->translated['S'])->toBe(['cash' => 120000, 'cap' => -100000, 'div' => 5000, 'rev' => -50000, 'exp' => 25000])
        ->and($r->consolidated)->not->toHaveKey('tr')
        ->and($r->summary)->toBe(['profit' => 25000, 'translation_difference' => 0]);
});

it('adds posted group entries and rolls an earlier period\'s profit and loss lines into retained earnings', function (): void {
    $r = (new ConsolidationCalculator)->run(new RunInput('USD', 2, '2026-01-01', '2026-03-31', chart(), [usdParent()], [
        ['entry_id' => 'E1', 'date' => '2025-12-31', 'member_id' => null, 'account_id' => 'exp', 'net' => 300, 'fc' => null, 'role_kind' => null],
        ['entry_id' => 'E1', 'date' => '2025-12-31', 'member_id' => null, 'account_id' => 'pay', 'net' => -300, 'fc' => null, 'role_kind' => null],
        ['entry_id' => 'E2', 'date' => '2026-02-01', 'member_id' => null, 'account_id' => 'exp', 'net' => 100, 'fc' => null, 'role_kind' => null],
        ['entry_id' => 'E2', 'date' => '2026-02-01', 'member_id' => null, 'account_id' => 'pay', 'net' => -100, 'fc' => null, 'role_kind' => null],
    ]));

    expect($r->consolidated)->toMatchArray(['re' => 300, 'exp' => 1100, 'pay' => -400])
        ->and(collect($r->cells)->pluck('entry_id')->unique()->values()->all())->toBe(['E1', 'E2'])
        ->and($r->isBalanced())->toBeTrue();
});

it('blocks when equity moved before the window since the opening position was stored', function (): void {
    // The stored opening says capital −100,000,000, but the books now hold −110,000,000 with no movement in the window:
    // capital was posted into a period already consolidated, so its historical rate is unknown.
    $k = kes(['cash' => 110000000, 'cap' => -110000000], [], [], kesOpening());

    expect(fn () => runOf(usdParent(), $k))->toThrow(DomainRuleViolation::class, 'changed before this period');
});

it('blocks a translated member without rates or an opening position, rather than guessing', function (): void {
    expect(fn () => runOf(usdParent(), kes(['cash' => 1, 'cap' => -1], [], [], kesOpening(), null)))->toThrow(DomainRuleViolation::class, 'No rate')
        ->and(fn () => runOf(usdParent(), kes(['cash' => 1, 'cap' => -1], [], [], null)))->toThrow(DomainRuleViolation::class, 'no opening position')
        ->and(fn () => runOf(usdParent(), kes(['cash' => 100, 'div' => 5, 'cap' => -105], [], [['account_id' => 'div', 'date' => '2026-02-15', 'net' => 5, 'rate' => null]], ['local' => ['cap' => -105], 'translated' => ['cap' => -1], 'tr' => 0])))
        ->toThrow(DomainRuleViolation::class, 'No rate');
});

it('takes the period average as the mean of the rate in force on each day of the window', function (): void {
    // 1.10 in force for 90 days, 1.20 for 1 day: (99 + 1.2) ÷ 91 = 1.10109890...
    expect(Rounding::dailyMean([['rate' => '1.10', 'days' => 90], ['rate' => '1.20', 'days' => 1]]))->toBe('1.10109890')
        ->and(Rounding::dailyMean([['rate' => '0.0077', 'days' => 31]]))->toBe('0.00770000');
});

it('starts from an opening entered by the accountant and continues the reserve from the opening reserve', function (): void {
    // Opening as of 31 Dec 2025, from the consolidation done before Ledgerly, in USD at historical rates: capital −800,000,
    // retained earnings −50,000, translation reserve +2,000 (a debit). The books then held capital KES −100,000,000 and
    // retained earnings −6,000,000. No activity in the quarter; the closing rate is 0.0075.
    $opening = ['local' => ['cap' => -100000000, 're' => -6000000], 'translated' => ['cap' => -800000, 're' => -50000], 'tr' => 2000];
    $r = runOf(usdParent(), kes(['cash' => 106000000, 'cap' => -100000000], [], [], $opening));

    // Cash 106,000,000 × 0.0075 = 795,000. Cumulative reserve = −(795,000 − 800,000 − 50,000) = 55,000;
    // the quarter's difference is 55,000 − 2,000 = 53,000.
    expect($r->translated['K'])->toBe(['cash' => 795000, 'cap' => -800000, 're' => -50000, 'tr' => 55000])
        ->and($r->states['K']['tr'])->toBe(55000)
        ->and($r->summary['translation_difference'])->toBe(53000);
});
