<?php

declare(strict_types=1);

use App\Domain\Billing\Prices;
use App\Domain\Shared\DomainRuleViolation;

it('reads a price for a currency and interval', function (): void {
    $json = ['USD' => ['monthly' => 4900, 'annual' => 47000], 'KES' => ['monthly' => null, 'annual' => null]];

    expect(Prices::of($json, 'USD', 'monthly'))->toBe(4900)
        ->and(Prices::of($json, 'USD', 'annual'))->toBe(47000)
        ->and(Prices::of($json, 'KES', 'monthly'))->toBeNull()
        ->and(Prices::of($json, 'EUR', 'monthly'))->toBeNull();
});

it('prices reject unknown currency and floats', function (array $bad): void {
    expect(fn () => Prices::validate($bad))->toThrow(DomainRuleViolation::class);
})->with([
    'unknown currency' => [['GBP' => ['monthly' => 100, 'annual' => 1000]]],
    'float' => [['USD' => ['monthly' => 49.5, 'annual' => 1000]]],
    'whole float' => [['USD' => ['monthly' => 4900.0, 'annual' => 47000]]],
    'numeric string' => [['USD' => ['monthly' => '4900', 'annual' => 47000]]],
    'negative' => [['USD' => ['monthly' => -1, 'annual' => 47000]]],
    'missing interval' => [['USD' => ['monthly' => 4900]]],
    'extra interval' => [['USD' => ['monthly' => 4900, 'annual' => 47000, 'weekly' => 1]]],
    'not a map' => [['USD' => 4900]],
]);

it('accepts integer minor units and custom-quote nulls', function (): void {
    $ok = ['USD' => ['monthly' => 4900, 'annual' => 47000], 'EUR' => ['monthly' => null, 'annual' => null]];

    expect(Prices::validate($ok))->toBe($ok);
});

it('rejects an unknown interval when reading', function (): void {
    expect(fn () => Prices::of([], 'USD', 'weekly'))->toThrow(InvalidArgumentException::class);
});
