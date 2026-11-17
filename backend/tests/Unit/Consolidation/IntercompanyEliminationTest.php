<?php

declare(strict_types=1);

use App\Domain\Consolidation\ConsolidationCalculator;
use App\Domain\Consolidation\MemberInput;
use App\Domain\Consolidation\RunInput;
use App\Domain\Consolidation\RunResult;

/*
 * Intercompany matching and eliminations (D16.7, plan 3c.29c), worked by hand. Net = debit − credit in minor units. The group
 * presents in USD; P and S keep USD books, K keeps KES books (closing 0.0075, average 0.0078).
 *
 * A pair's difference is side A + side B after translation. The matched amount is the smaller side when the two sides are
 * opposite, and only that eliminates ("matched balances ... eliminate"); the rest stays in the consolidated figures and
 * shows in the matching report. A difference above the group's tolerance blocks approval until a second person accepts it.
 */

/** @return array<string, array{type: string, role: string|null}> */
function icChart(): array
{
    return [
        'cash' => ['type' => 'asset', 'role' => null], 'icr' => ['type' => 'asset', 'role' => null], 'loan' => ['type' => 'asset', 'role' => null],
        'icp' => ['type' => 'liability', 'role' => null], 'loanp' => ['type' => 'liability', 'role' => null],
        'cap' => ['type' => 'equity', 'role' => null], 'div' => ['type' => 'equity', 'role' => null],
        're' => ['type' => 'equity', 'role' => 'retained_earnings'], 'tr' => ['type' => 'equity', 'role' => 'translation_reserve'],
        'icrev' => ['type' => 'income', 'role' => null], 'divinc' => ['type' => 'income', 'role' => null], 'fx' => ['type' => 'income', 'role' => null],
        'icexp' => ['type' => 'expense', 'role' => null],
    ];
}

/**
 * @param  array<string, int>  $bs
 * @param  array<string, int>  $pl
 * @param  list<array<string, mixed>>  $ic
 * @param  list<array{account_id: string, net: int}>  $ni
 */
function icMember(string $id, array $bs, array $pl, array $ic, array $ni = [], array $moves = []): MemberInput
{
    if ($id === 'K') {
        // K's books hold no equity of their own here (its figures sum to zero), so its opening is empty.
        return new MemberInput('K', false, 'KES', 2, '0.0075', '0.0078', $bs, $pl, $moves, ['local' => [], 'translated' => [], 'tr' => 0], $ic, $ni);
    }

    return new MemberInput($id, $id === 'P', 'USD', 2, null, null, $bs, $pl, $moves, null, $ic, $ni);
}

/** A slice of intragroup items: class, counterparty, group account, local net, amounts per transaction currency. */
function slice(string $class, string $counterparty, string $account, int $net, ?array $currencies = null, array $moves = []): array
{
    return ['class' => $class, 'counterparty' => $counterparty, 'account_id' => $account, 'net' => $net, 'currencies' => $currencies ?? ['USD' => $net], 'moves' => $moves];
}

function icRun(int $tolerance, MemberInput ...$members): RunResult
{
    return (new ConsolidationCalculator)->run(new RunInput('USD', 2, '2026-01-01', '2026-03-31', icChart(), array_values($members), [], $tolerance));
}

/** @param list<array{account_id: string, member_id: string|null, net: int}> $lines */
function linesOf(array $lines): array
{
    $out = [];
    foreach ($lines as $l) {
        $out[] = [$l['member_id'], $l['account_id'], $l['net']];
    }

    return $out;
}

it('eliminates a receivable against the matching payable in full, balanced, with nothing left', function (): void {
    $p = icMember('P', ['cash' => 900000, 'icr' => 100000, 'cap' => -1000000], [], [slice('balance', 'S', 'icr', 100000)]);
    $s = icMember('S', ['cash' => 100000, 'icp' => -100000], [], [slice('balance', 'P', 'icp', -100000)]);

    $r = icRun(0, $p, $s);

    expect($r->matches)->toBe([[
        'member_a' => 'P', 'member_b' => 'S', 'class' => 'balance', 'side_a' => 100000, 'side_b' => -100000,
        'difference' => 0, 'matched' => 100000, 'cause' => null, 'exceeds_tolerance' => false,
        'detail' => ['currencies' => ['USD' => 0]],
    ]])
        ->and($r->proposed)->toHaveCount(1)
        ->and($r->proposed[0]['kind'])->toBe('elimination')
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icr', -100000], ['S', 'icp', 100000]])
        ->and($r->consolidated)->not->toHaveKey('icr')->and($r->consolidated)->not->toHaveKey('icp')
        ->and($r->isBalanced())->toBeTrue()
        ->and(array_values(array_filter($r->cells, fn (array $c): bool => $c['source'] === 'elimination')))->toHaveCount(2);
});

it('eliminates only the matched part of intragroup sales and purchases and reports the rest as a timing difference above tolerance', function (): void {
    // P sold 1,000.00 to S; S has booked only 980.00 of it.
    $p = icMember('P', ['cash' => 100000, 'cap' => 0], ['icrev' => -100000], [slice('income_expense', 'S', 'icrev', -100000)]);
    $s = icMember('S', ['cash' => -98000], ['icexp' => 98000], [slice('income_expense', 'P', 'icexp', 98000)]);

    $r = icRun(1000, $p, $s);

    expect($r->matches[0])->toMatchArray(['side_a' => -100000, 'side_b' => 98000, 'difference' => -2000, 'matched' => 98000, 'cause' => 'timing', 'exceeds_tolerance' => true])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icrev', 98000], ['S', 'icexp', -98000]])
        ->and($r->consolidated['icrev'])->toBe(-2000)->and($r->consolidated)->not->toHaveKey('icexp')
        ->and($r->summary['profit'])->toBe(2000)   // the elimination does not change profit
        ->and($r->isBalanced())->toBeTrue();

    expect(icRun(2000, $p, $s)->matches[0]['exceeds_tolerance'])->toBeFalse();
});

it('matches a KES subsidiary\'s USD payable after translation at closing and names the difference FX', function (): void {
    // P lent USD 10,000.00 to K. K carries it at 130 KES/USD = KES 1,300,000.00; closing 0.0075 → USD 9,750.00.
    $p = icMember('P', ['cash' => 0, 'loan' => 1000000, 'cap' => -1000000], [], [slice('loan', 'K', 'loan', 1000000)]);
    $k = icMember('K', ['cash' => 130000000, 'loanp' => -130000000], [], [slice('loan', 'P', 'loanp', -130000000, ['USD' => -1000000])]);

    $r = icRun(0, $p, $k);

    expect($r->matches[0])->toMatchArray(['member_a' => 'K', 'member_b' => 'P', 'side_a' => -975000, 'side_b' => 1000000, 'difference' => 25000, 'matched' => 975000, 'cause' => 'fx'])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['K', 'loanp', 975000], ['P', 'loan', -975000]])
        ->and($r->consolidated['loan'])->toBe(25000)
        ->and($r->isBalanced())->toBeTrue();
});

it('reports a missing counterpart and eliminates nothing', function (): void {
    $p = icMember('P', ['cash' => 95000, 'icr' => 5000, 'cap' => -100000], [], [slice('balance', 'S', 'icr', 5000)]);
    $s = icMember('S', ['cash' => 0], [], []);

    $r = icRun(0, $p, $s);

    expect($r->matches[0])->toMatchArray(['side_a' => 5000, 'side_b' => 0, 'difference' => 5000, 'matched' => 0, 'cause' => 'missing_counterpart', 'exceeds_tolerance' => true])
        ->and($r->proposed)->toBe([])->and($r->consolidated['icr'])->toBe(5000);
});

it('eliminates every line of a pair whose members owe each other both ways', function (): void {
    // P: owed 100.00 by S and owes S 10.00; S the mirror. Net 90.00 each way, but all four balances go.
    $p = icMember('P', ['cash' => -90, 'icr' => 10000, 'icp' => -1000, 'cap' => -8910], [], [slice('balance', 'S', 'icr', 10000), slice('balance', 'S', 'icp', -1000)]);
    $s = icMember('S', ['cash' => 9000, 'icr' => 1000, 'icp' => -10000], [], [slice('balance', 'P', 'icr', 1000), slice('balance', 'P', 'icp', -10000)]);

    $r = icRun(0, $p, $s);

    expect($r->matches[0])->toMatchArray(['side_a' => 9000, 'side_b' => -9000, 'difference' => 0, 'matched' => 9000])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icp', 1000], ['P', 'icr', -10000], ['S', 'icr', -1000], ['S', 'icp', 10000]])
        ->and($r->consolidated)->not->toHaveKey('icr')->and($r->consolidated)->not->toHaveKey('icp');
});

it('eliminates an intragroup dividend against the receiving company\'s income', function (): void {
    $p = icMember('P', ['cash' => 50000, 'cap' => -50000], ['divinc' => -50000], [slice('dividend', 'S', 'divinc', -50000)]);
    $s = icMember('S', ['cash' => -50000, 'div' => 50000], [], [slice('dividend', 'P', 'div', 50000, null, [['net' => 50000, 'rate' => null]])]);

    $r = icRun(0, $p, $s);

    expect($r->matches[0])->toMatchArray(['class' => 'dividend', 'difference' => 0, 'matched' => 50000])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'divinc', 50000], ['S', 'div', -50000]])
        ->and($r->consolidated)->not->toHaveKey('divinc')->and($r->consolidated)->not->toHaveKey('div');
});

it('translates a dividend paid by a KES subsidiary at the rate of its day, like the equity it comes from', function (): void {
    // K pays KES 1,000,000.00 on a day at 0.0077 = USD 7,700.00; P received USD 7,700.00.
    $k = new MemberInput('K', false, 'KES', 2, '0.0075', '0.0078', ['cash' => -100000000, 'div' => 100000000], [],
        [['account_id' => 'div', 'date' => '2026-02-15', 'net' => 100000000, 'rate' => '0.0077']],
        ['local' => ['re' => 0], 'translated' => ['re' => 0], 'tr' => 0],
        [slice('dividend', 'P', 'div', 100000000, ['KES' => 100000000], [['net' => 100000000, 'rate' => '0.0077']])]);
    $p = icMember('P', ['cash' => 770000, 'cap' => -770000], ['divinc' => -770000], [slice('dividend', 'K', 'divinc', -770000)]);

    $r = icRun(0, $p, $k);

    expect($r->matches[0])->toMatchArray(['side_a' => 770000, 'side_b' => -770000, 'difference' => 0, 'cause' => null])
        ->and($r->isBalanced())->toBeTrue();
});

it('rounds each translated slice once, half up, in minor units', function (): void {
    // KES 2.00 at 0.0075 = 1.5 cents → 2 (half up), which matches P's 2 cents exactly.
    $k = new MemberInput('K', false, 'KES', 2, '0.0075', '0.0078', ['cash' => -200, 'icp' => 0, 'loanp' => 200], [], [], ['local' => [], 'translated' => [], 'tr' => 0],
        [slice('balance', 'P', 'loanp', 200, ['KES' => 200])]);
    $p = icMember('P', ['cash' => 2, 'loan' => -2, 'cap' => 0], [], [slice('balance', 'K', 'loan', -2)]);

    expect(icRun(0, $p, $k)->matches[0])->toMatchArray(['side_a' => 2, 'side_b' => -2, 'difference' => 0, 'matched' => 2]);
});

it('moves the exchange difference on a net-investment loan from profit or loss to the translation reserve (IAS 21.32, D16.7)', function (): void {
    // K's books show an exchange gain of KES 1,000,000.00 on a loan designated part of P's net investment; average 0.0078.
    $k = new MemberInput('K', false, 'KES', 2, '0.0075', '0.0078', ['cash' => 100000000], ['fx' => -100000000], [], ['local' => [], 'translated' => [], 'tr' => 0],
        [], [['account_id' => 'fx', 'net' => -100000000]]);
    $p = icMember('P', ['cash' => 0], [], []);

    $r = icRun(0, $p, $k);
    $ni = array_values(array_filter($r->proposed, fn (array $e): bool => $e['kind'] === 'net_investment'));

    expect($ni)->toHaveCount(1)->and($ni[0]['member_id'])->toBe('K')
        ->and(linesOf($ni[0]['lines']))->toBe([['K', 'fx', 780000], ['K', 'tr', -780000]])
        ->and($r->consolidated)->not->toHaveKey('fx')
        ->and($r->summary['profit'])->toBe(0)
        ->and($r->isBalanced())->toBeTrue();
});

it('ignores a counterparty that is not part of the run', function (): void {
    $p = icMember('P', ['cash' => 95000, 'icr' => 5000, 'cap' => -100000], [], [slice('balance', 'GONE', 'icr', 5000)]);

    expect(icRun(0, $p)->matches)->toBe([]);
});
