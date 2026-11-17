<?php

declare(strict_types=1);

use App\Domain\Fiscal\CalendarType;
use App\Domain\Fiscal\FiscalCalendar;
use App\Domain\Fiscal\PeriodKind;
use App\Domain\FixedAssets\AssetState;
use App\Domain\FixedAssets\DepreciationCalculator;
use App\Domain\FixedAssets\DepreciationCalendar;
use App\Domain\FixedAssets\DepreciationMethod;

/** The regular periods of a planned year as the calendar takes them, adjustment period left out. */
function depreciationCalendarFor(CalendarType $type, string $start, ?int $extra = null): DepreciationCalendar
{
    $periods = [];
    foreach (FiscalCalendar::plan($type, new DateTimeImmutable($start), $extra) as $p) {
        if ($p->kind === PeriodKind::Regular) {
            $periods[] = [$p->startsOn->format('Y-m-d'), $p->endsOn->format('Y-m-d')];
        }
    }

    return new DepreciationCalendar($periods);
}

function fiscalState(array $o = []): AssetState
{
    return new AssetState(
        $o['cost'] ?? 120000, $o['residual'] ?? 0, $o['dep'] ?? 0, 0, DepreciationMethod::StraightLine,
        array_key_exists('life', $o) ? $o['life'] : 12, null, null, 0, $o['charged'] ?? 0, $o['from'] ?? '2027-01-01', $o['through'] ?? null,
    );
}

/** Depreciates period by period as consecutive runs would, until nothing is due. @return list<array{0: string, 1: int}> */
function fiscalSeries(AssetState $s, DepreciationCalendar $cal, string $until): array
{
    $out = [];
    foreach (DepreciationCalculator::monthsDue($s, $until, $cal) as $end) {
        if (DepreciationCalculator::monthsDue($s, $end, $cal) === []) {
            break;
        }
        $c = DepreciationCalculator::charge($s, [$end]);
        $out[] = [$end, $c->amount];
        $s = new AssetState(
            $s->cost, $s->residual, $s->accumulatedDepreciation + $c->amount, 0, $s->method, $s->lifeMonths, null, null, 0, $c->newMonthsCharged, $s->availableFrom, $c->through,
        );
    }

    return $out;
}

it('knows the period a date falls in, and falls back to calendar months outside every year', function (): void {
    $cal = depreciationCalendarFor(CalendarType::Weeks445, '2027-01-01');

    expect($cal->periodOf('2027-02-10'))->toBe(['2027-01-29', '2027-02-25'])->and($cal->endOf('2027-03-31'))->toBe('2027-04-01')
        ->and($cal->endOfPrevious('2027-02-25'))->toBe('2027-01-28')->and($cal->isPeriodEnd('2027-04-01'))->toBeTrue()
        ->and($cal->isPeriodEnd('2027-03-31'))->toBeFalse()
        // After the last period (30 December 2027) a calendar month stands in, starting the day after.
        ->and($cal->periodOf('2027-12-31'))->toBe(['2027-12-31', '2027-12-31'])->and($cal->endOf('2028-01-15'))->toBe('2028-01-31')
        // Before the first period it stops the day before it.
        ->and($cal->periodOf('2026-12-15'))->toBe(['2026-12-01', '2026-12-31'])
        ->and(DepreciationCalendar::calendarMonths()->endsBetween('2026-01-15', '2026-03-02'))->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('4-4-5: 1,200.00 over 12 periods charges 100.00 per fiscal period and 1,200.00 in all', function (): void {
    $cal = depreciationCalendarFor(CalendarType::Weeks445, '2027-01-01');
    $series = fiscalSeries(fiscalState(), $cal, '2027-12-30');

    expect($series)->toHaveCount(12)->and(array_unique(array_column($series, 1)))->toBe([10000])->and(array_sum(array_column($series, 1)))->toBe(120000)
        ->and($series[0][0])->toBe('2027-01-28')->and($series[2][0])->toBe('2027-04-01')->and($series[11][0])->toBe('2027-12-30');
    // Period 3 ends 1 April: it is one period's charge, not the March and April a calendar count would make of it.
    expect(DepreciationCalculator::monthsDue(fiscalState(['through' => '2027-02-25', 'charged' => 2, 'dep' => 20000]), '2027-04-01', $cal))->toBe(['2027-04-01']);
});

it('last period takes the rounding: 1,000 over 12 periods, 4-5-4 with a 53rd week', function (): void {
    $cal = depreciationCalendarFor(CalendarType::Weeks454, '2027-01-01', 12);
    $amounts = array_column(fiscalSeries(fiscalState(['cost' => 1000]), $cal, '2029-01-04'), 1);

    expect($amounts)->toHaveCount(12)->and(array_sum($amounts))->toBe(1000)->and($amounts[0])->toBe(83);
});

it('follows the existing convention for an asset available mid-period: the whole period is charged, from that period', function (): void {
    $cal = depreciationCalendarFor(CalendarType::Weeks445, '2027-01-01');
    $s = fiscalState(['from' => '2027-02-10']);

    expect(DepreciationCalculator::monthsDue($s, '2027-01-28', $cal))->toBe([])
        ->and(DepreciationCalculator::monthsDue($s, '2027-02-25', $cal))->toBe(['2027-02-25'])
        ->and(DepreciationCalculator::monthsDue($s, '2027-04-01', $cal))->toBe(['2027-02-25', '2027-04-01']);
});

it('never counts the adjustment period: a year of 12 regular periods plus the adjustment period gives 12 charges, not 13', function (): void {
    $cal = depreciationCalendarFor(CalendarType::Weeks445, '2027-01-01');
    $series = fiscalSeries(fiscalState(['life' => 24, 'cost' => 240000]), $cal, '2027-12-30');

    // The adjustment period shares the last regular period's last day, so no 13th charge date exists.
    expect($series)->toHaveCount(12)
        ->and(DepreciationCalculator::monthsDue(fiscalState(['life' => 24, 'through' => '2027-12-30', 'charged' => 12]), '2027-12-30', $cal))->toBe([]);
});

it('is identical to the calendar-month figures for a monthly fiscal year', function (): void {
    $monthly = depreciationCalendarFor(CalendarType::Monthly, '2026-01-01');
    $state = fiscalState(['cost' => 1000, 'residual' => 100, 'life' => 7, 'from' => '2026-01-05']);

    expect(DepreciationCalculator::monthsDue($state, '2026-12-31', $monthly))->toBe(DepreciationCalculator::monthsDue($state, '2026-12-31'))
        ->and(fiscalSeries($state, $monthly, '2026-12-31'))->toBe(fiscalSeries($state, DepreciationCalendar::calendarMonths(), '2026-12-31'))
        ->and(array_column(fiscalSeries($state, $monthly, '2026-12-31'), 1))->toBe([129, 129, 128, 129, 128, 129, 128]);
});
