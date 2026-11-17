<?php

declare(strict_types=1);

use App\Domain\Consolidation\ConsolidationCalculator;
use App\Domain\Consolidation\MemberInput;
use App\Domain\Consolidation\RunInput;
use App\Domain\Consolidation\RunResult;

/*
 * Intercompany matching and eliminations (D16.7, plan 3c.29c, owner's answers of 2026-11-17), worked by hand. Net = debit −
 * credit in minor units. The group presents in USD; P and S keep USD books, K keeps KES books (closing 0.0075, average 0.0078).
 *
 * Both sides of a pair eliminate in full; the difference (side A + side B) goes to the group's intercompany differences
 * account (`icd`, profit or loss). For a balance or a loan only its movement since the previous approved run is profit or
 * loss; what earlier runs recognised is in retained earnings. For a dividend the FX part (rate of its day against the
 * average) goes to the translation reserve. Above the tolerance a difference blocks approval until accepted.
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
        'icexp' => ['type' => 'expense', 'role' => null], 'icd' => ['type' => 'expense', 'role' => 'ic_difference'],
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

/** @param array<string, int> $prior */
function icRun(int $tolerance, array $prior, MemberInput ...$members): RunResult
{
    return (new ConsolidationCalculator)->run(new RunInput('USD', 2, '2026-01-01', '2026-03-31', icChart(), array_values($members), [], $tolerance, $prior));
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

    $r = icRun(0, [], $p, $s);

    expect($r->matches)->toBe([[
        'member_a' => 'P', 'member_b' => 'S', 'class' => 'balance', 'side_a' => 100000, 'side_b' => -100000,
        'difference' => 0, 'to_reserve' => 0, 'recognised_before' => 0, 'matched' => 100000, 'cause' => null, 'exceeds_tolerance' => false,
        'detail' => ['currencies' => ['USD' => 0]],
    ]])
        ->and($r->proposed)->toHaveCount(1)
        ->and($r->proposed[0]['kind'])->toBe('elimination')
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icr', -100000], ['S', 'icp', 100000]])
        ->and($r->consolidated)->not->toHaveKey('icr')->and($r->consolidated)->not->toHaveKey('icp')->and($r->consolidated)->not->toHaveKey('icd')
        ->and($r->isBalanced())->toBeTrue()
        ->and(array_values(array_filter($r->cells, fn (array $c): bool => $c['source'] === 'elimination')))->toHaveCount(2);
});

it('eliminates intragroup sales and purchases in full and takes the whole period difference to profit or loss', function (): void {
    // P sold 1,000.00 to S; S has booked only 980.00 of it.
    $p = icMember('P', ['cash' => 100000, 'cap' => 0], ['icrev' => -100000], [slice('income_expense', 'S', 'icrev', -100000)]);
    $s = icMember('S', ['cash' => -98000], ['icexp' => 98000], [slice('income_expense', 'P', 'icexp', 98000)]);

    $r = icRun(1000, [], $p, $s);

    expect($r->matches[0])->toMatchArray(['side_a' => -100000, 'side_b' => 98000, 'difference' => -2000, 'matched' => 98000, 'cause' => 'timing', 'exceeds_tolerance' => true])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icrev', 100000], ['S', 'icexp', -98000], [null, 'icd', -2000]])
        ->and($r->consolidated)->not->toHaveKey('icrev')->and($r->consolidated)->not->toHaveKey('icexp')
        ->and($r->consolidated['icd'])->toBe(-2000)
        ->and($r->summary['profit'])->toBe(2000)   // a flow's elimination does not change profit
        ->and($r->isBalanced())->toBeTrue();

    expect(icRun(2000, [], $p, $s)->matches[0]['exceeds_tolerance'])->toBeFalse();
});

it('matches a KES subsidiary\'s USD loan after translation at closing and takes the FX difference to profit or loss', function (): void {
    // P lent USD 10,000.00 to K. K carries it at 130 KES/USD = KES 1,300,000.00; closing 0.0075 → USD 9,750.00.
    $p = icMember('P', ['cash' => 0, 'loan' => 1000000, 'cap' => -1000000], [], [slice('loan', 'K', 'loan', 1000000)]);
    $k = icMember('K', ['cash' => 130000000, 'loanp' => -130000000], [], [slice('loan', 'P', 'loanp', -130000000, ['USD' => -1000000])]);

    $r = icRun(0, [], $p, $k);

    expect($r->matches[0])->toMatchArray(['member_a' => 'K', 'member_b' => 'P', 'side_a' => -975000, 'side_b' => 1000000, 'difference' => 25000, 'matched' => 975000, 'cause' => 'fx'])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['K', 'loanp', 975000], ['P', 'loan', -1000000], [null, 'icd', 25000]])
        ->and($r->consolidated)->not->toHaveKey('loan')->and($r->consolidated)->not->toHaveKey('loanp')
        ->and($r->isBalanced())->toBeTrue();
});

it('eliminates a side with no counterpart and takes it all as the difference', function (): void {
    $p = icMember('P', ['cash' => 95000, 'icr' => 5000, 'cap' => -100000], [], [slice('balance', 'S', 'icr', 5000)]);
    $s = icMember('S', ['cash' => 0], [], []);

    $r = icRun(0, [], $p, $s);

    expect($r->matches[0])->toMatchArray(['side_a' => 5000, 'side_b' => 0, 'difference' => 5000, 'matched' => 0, 'cause' => 'missing_counterpart', 'exceeds_tolerance' => true])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icr', -5000], [null, 'icd', 5000]])
        ->and($r->consolidated)->not->toHaveKey('icr')->and($r->consolidated['icd'])->toBe(5000);
});

it('eliminates every line of a pair whose members owe each other both ways', function (): void {
    $p = icMember('P', ['cash' => -90, 'icr' => 10000, 'icp' => -1000, 'cap' => -8910], [], [slice('balance', 'S', 'icr', 10000), slice('balance', 'S', 'icp', -1000)]);
    $s = icMember('S', ['cash' => 9000, 'icr' => 1000, 'icp' => -10000], [], [slice('balance', 'P', 'icr', 1000), slice('balance', 'P', 'icp', -10000)]);

    $r = icRun(0, [], $p, $s);

    expect($r->matches[0])->toMatchArray(['side_a' => 9000, 'side_b' => -9000, 'difference' => 0, 'matched' => 9000])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icp', 1000], ['P', 'icr', -10000], ['S', 'icp', 10000], ['S', 'icr', -1000]])
        ->and($r->consolidated)->not->toHaveKey('icr')->and($r->consolidated)->not->toHaveKey('icp');
});

it('takes only the movement of a balance difference to profit or loss; the part earlier runs recognised sits in retained earnings', function (): void {
    // The receivable is 1,000.00 and the payable 980.00, as last run: 20.00 recognised before, nothing new this run.
    $p = icMember('P', ['cash' => -100000, 'icr' => 100000], [], [slice('balance', 'S', 'icr', 100000)]);
    $s = icMember('S', ['cash' => 98000, 'icp' => -98000], [], [slice('balance', 'P', 'icp', -98000)]);

    $r = icRun(0, ['P|S|balance' => 2000], $p, $s);

    expect($r->matches[0])->toMatchArray(['difference' => 2000, 'recognised_before' => 2000])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'icr', -100000], ['S', 'icp', 98000], [null, 're', 2000]])
        ->and($r->consolidated)->not->toHaveKey('icd')
        ->and($r->summary['profit'])->toBe(0);

    // Settled since: the difference is gone, so profit or loss takes back what earlier runs recognised.
    $settled = icRun(0, ['P|S|balance' => 2000], icMember('P', ['cash' => 0], [], []), icMember('S', ['cash' => 0], [], []));
    expect($settled->matches[0])->toMatchArray(['side_a' => 0, 'side_b' => 0, 'difference' => 0, 'recognised_before' => 2000])
        ->and(linesOf($settled->proposed[0]['lines']))->toBe([[null, 'icd', -2000], [null, 're', 2000]])
        ->and($settled->isBalanced())->toBeTrue();
});

it('eliminates an intragroup dividend against the receiving company\'s income', function (): void {
    $p = icMember('P', ['cash' => 50000, 'cap' => -50000], ['divinc' => -50000], [slice('dividend', 'S', 'divinc', -50000, null, [['net' => -50000, 'rate' => null]])]);
    $s = icMember('S', ['cash' => -50000, 'div' => 50000], [], [slice('dividend', 'P', 'div', 50000, null, [['net' => 50000, 'rate' => null]])]);

    $r = icRun(0, [], $p, $s);

    expect($r->matches[0])->toMatchArray(['class' => 'dividend', 'difference' => 0, 'to_reserve' => 0, 'matched' => 50000])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['P', 'divinc', 50000], ['S', 'div', -50000]])
        ->and($r->consolidated)->not->toHaveKey('divinc')->and($r->consolidated)->not->toHaveKey('div');
});

it('sends the FX part of a dividend received by a KES subsidiary to the translation reserve, so it eliminates with no difference', function (): void {
    // P pays USD 7,700.00 on a day at 0.0077; K books KES 1,000,000.00 of income, translated at the average 0.0078 = 7,800.00.
    // At its day's rate the income is 7,700.00: the 100.00 between is FX, to the translation reserve.
    $p = icMember('P', ['cash' => -770000, 'div' => 770000], [], [slice('dividend', 'K', 'div', 770000, null, [['net' => 770000, 'rate' => null]])]);
    $k = icMember('K', ['cash' => 100000000], ['divinc' => -100000000], [slice('dividend', 'P', 'divinc', -100000000, ['KES' => -100000000], [['net' => -100000000, 'rate' => '0.0077']])]);

    $r = icRun(0, [], $p, $k);

    expect($r->matches[0])->toMatchArray(['side_a' => -780000, 'side_b' => 770000, 'difference' => 0, 'to_reserve' => -10000, 'cause' => null, 'exceeds_tolerance' => false])
        ->and(linesOf($r->proposed[0]['lines']))->toBe([['K', 'divinc', 780000], ['P', 'div', -770000], [null, 'tr', -10000]])
        ->and($r->isBalanced())->toBeTrue();

    // P paid only 7,600.00 for the same income: the 100.00 FX part still goes to the reserve, the other 100.00 is a difference.
    $p2 = icMember('P', ['cash' => -760000, 'div' => 760000], [], [slice('dividend', 'K', 'div', 760000, null, [['net' => 760000, 'rate' => null]])]);
    $r2 = icRun(0, [], $p2, $k);
    expect($r2->matches[0])->toMatchArray(['difference' => -10000, 'to_reserve' => -10000, 'cause' => 'timing'])
        ->and(linesOf($r2->proposed[0]['lines']))->toBe([['K', 'divinc', 780000], ['P', 'div', -760000], [null, 'tr', -10000], [null, 'icd', -10000]]);
});

it('translates a dividend paid by a KES subsidiary at the rate of its day, like the equity it comes from', function (): void {
    $k = new MemberInput('K', false, 'KES', 2, '0.0075', '0.0078', ['cash' => -100000000, 'div' => 100000000], [],
        [['account_id' => 'div', 'date' => '2026-02-15', 'net' => 100000000, 'rate' => '0.0077']],
        ['local' => ['re' => 0], 'translated' => ['re' => 0], 'tr' => 0],
        [slice('dividend', 'P', 'div', 100000000, ['KES' => 100000000], [['net' => 100000000, 'rate' => '0.0077']])]);
    $p = icMember('P', ['cash' => 770000, 'cap' => -770000], ['divinc' => -770000], [slice('dividend', 'K', 'divinc', -770000, null, [['net' => -770000, 'rate' => null]])]);

    $r = icRun(0, [], $p, $k);

    expect($r->matches[0])->toMatchArray(['side_a' => 770000, 'side_b' => -770000, 'difference' => 0, 'to_reserve' => 0, 'cause' => null])
        ->and($r->isBalanced())->toBeTrue();
});

it('rounds each translated slice once, half up, in minor units', function (): void {
    // KES 2.00 at 0.0075 = 1.5 cents → 2 (half up), which matches P's 2 cents exactly.
    $k = new MemberInput('K', false, 'KES', 2, '0.0075', '0.0078', ['cash' => -200, 'icp' => 0, 'loanp' => 200], [], [], ['local' => [], 'translated' => [], 'tr' => 0],
        [slice('balance', 'P', 'loanp', 200, ['KES' => 200])]);
    $p = icMember('P', ['cash' => 2, 'loan' => -2, 'cap' => 0], [], [slice('balance', 'K', 'loan', -2)]);

    expect(icRun(0, [], $p, $k)->matches[0])->toMatchArray(['side_a' => 2, 'side_b' => -2, 'difference' => 0, 'matched' => 2]);
});

it('moves the exchange difference on a net-investment loan from profit or loss to the translation reserve (IAS 21.32, D16.7)', function (): void {
    // K's books show an exchange gain of KES 1,000,000.00 on a loan designated part of P's net investment; average 0.0078.
    $k = new MemberInput('K', false, 'KES', 2, '0.0075', '0.0078', ['cash' => 100000000], ['fx' => -100000000], [], ['local' => [], 'translated' => [], 'tr' => 0],
        [], [['account_id' => 'fx', 'net' => -100000000]]);
    $p = icMember('P', ['cash' => 0], [], []);

    $r = icRun(0, [], $p, $k);
    $ni = array_values(array_filter($r->proposed, fn (array $e): bool => $e['kind'] === 'net_investment'));

    expect($ni)->toHaveCount(1)->and($ni[0]['member_id'])->toBe('K')
        ->and(linesOf($ni[0]['lines']))->toBe([['K', 'fx', 780000], ['K', 'tr', -780000]])
        ->and($r->consolidated)->not->toHaveKey('fx')
        ->and($r->summary['profit'])->toBe(0)
        ->and($r->isBalanced())->toBeTrue();
});

it('ignores a counterparty that is not part of the run', function (): void {
    $p = icMember('P', ['cash' => 95000, 'icr' => 5000, 'cap' => -100000], [], [slice('balance', 'GONE', 'icr', 5000)]);

    expect(icRun(0, ['GONE|P|balance' => 100], $p)->matches)->toBe([]);
});
