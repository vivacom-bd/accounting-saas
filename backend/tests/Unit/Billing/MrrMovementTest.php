<?php

declare(strict_types=1);

use App\Domain\Billing\MrrMovement;

it('classifies the movement between two monthly amounts', function (?int $old, ?int $new, bool $returning, ?array $expected): void {
    expect(MrrMovement::of($old, $new, $returning))->toBe($expected);
})->with([
    'new' => [null, 4900, false, ['kind' => 'new', 'delta' => 4900]],
    'new from zero' => [0, 4900, false, ['kind' => 'new', 'delta' => 4900]],
    'reactivation' => [null, 4900, true, ['kind' => 'reactivation', 'delta' => 4900]],
    'expansion' => [4900, 14900, false, ['kind' => 'expansion', 'delta' => 10000]],
    'contraction' => [14900, 4900, false, ['kind' => 'contraction', 'delta' => -10000]],
    'churn' => [4900, null, false, ['kind' => 'churn', 'delta' => -4900]],
    'churn to zero' => [4900, 0, false, ['kind' => 'churn', 'delta' => -4900]],
    'no change' => [4900, 4900, false, null],
    'nothing to nothing' => [null, null, false, null],
    'zero to nothing' => [0, null, false, null],
]);

it('normalises an annual amount to one twelfth, rounding half up', function (int $annual, int $monthly): void {
    expect(MrrMovement::monthly($annual, 'year'))->toBe($monthly);
})->with([
    'exact' => [48000, 4000],
    'rounds up above .5' => [47001, 3917],   // 3916.75 -> 3917
    'rounds up at .5' => [18, 2],            // 1.5 -> 2
    'rounds down below .5' => [17, 1],       // 1.4166 -> 1
    'zero' => [0, 0],
]);

it('leaves a monthly amount as it is', function (): void {
    expect(MrrMovement::monthly(4900, 'month'))->toBe(4900);
});

it('refuses an unknown interval', function (): void {
    MrrMovement::monthly(100, 'week');
})->throws(InvalidArgumentException::class);
