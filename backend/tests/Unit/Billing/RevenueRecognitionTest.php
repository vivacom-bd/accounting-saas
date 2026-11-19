<?php

declare(strict_types=1);

use App\Domain\Billing\RevenueRecognition;

function rr(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
}

it('spreads an annual line over twelve months and sums exactly to the total', function (): void {
    $shares = RevenueRecognition::split(120000, rr('2026-01-01'), rr('2027-01-01'));

    expect($shares)->toHaveCount(12)
        ->and(array_sum($shares))->toBe(120000)
        // Straight-line by whole days: a 31-day month earns more than a 28-day one.
        ->and($shares['2026-01'])->toBe(10191)
        ->and($shares['2026-02'])->toBe(9205);
});

it('puts the rounding remainder in the final month so the total is exact', function (): void {
    // 2026-01-01 .. 2026-04-01: January 31 days, February 28, March 31 (90 in all).
    $shares = RevenueRecognition::split(10000, rr('2026-01-01'), rr('2026-04-01'));

    expect(array_sum($shares))->toBe(10000)
        ->and($shares['2026-01'])->toBe(3444)
        ->and($shares['2026-02'])->toBe(3111)
        ->and($shares['2026-03'])->toBe(3445);
});

it('recognises only the days in each month for a mid-month start', function (): void {
    // 2026-01-16 .. 2026-03-16: January 16 days, February 28, March 15 (59 in all).
    $shares = RevenueRecognition::split(5900, rr('2026-01-16'), rr('2026-03-16'));

    expect(array_sum($shares))->toBe(5900)
        ->and($shares)->toHaveKeys(['2026-01', '2026-02', '2026-03'])
        ->and($shares['2026-01'])->toBe(1600)
        ->and($shares['2026-02'])->toBe(2800)
        ->and($shares['2026-03'])->toBe(1500);
});

it('handles a leap-year February', function (): void {
    $shares = RevenueRecognition::split(36600, rr('2028-02-01'), rr('2028-03-01'));

    expect($shares)->toBe(['2028-02' => 36600]);
});

it('recognises nothing outside the covered months and the whole amount inside', function (): void {
    $start = rr('2026-01-01');
    $end = rr('2026-02-01');

    expect(RevenueRecognition::inMonth(5000, $start, $end, '2026-01'))->toBe(5000)
        ->and(RevenueRecognition::inMonth(5000, $start, $end, '2026-02'))->toBe(0)
        ->and(RevenueRecognition::inMonth(5000, $start, $end, '2025-12'))->toBe(0);
});

it('recognises a zero-length line in its start month', function (): void {
    expect(RevenueRecognition::split(700, rr('2026-03-01'), rr('2026-03-01')))->toBe(['2026-03' => 700]);
});

it('returns nothing for a zero amount', function (): void {
    expect(RevenueRecognition::split(0, rr('2026-01-01'), rr('2026-02-01')))->toBe([]);
});
