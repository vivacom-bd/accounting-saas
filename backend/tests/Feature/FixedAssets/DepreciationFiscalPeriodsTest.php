<?php

declare(strict_types=1);

/*
 * Plan 3c.15d: one fiscal period is one month of useful life, whatever its length. FY2027 is a 4-4-5 year (periods of 28, 28 and 35
 * days), so the third period ends on 1 April and its depreciation must still be one period's charge, not a calendar-month catch-up.
 */

beforeEach(function (): void {
    $this->setUpAssets();
    $this->category = $this->assetCategory(['default_life_months' => 12]);
    $year = $this->asPerson($this->owner)->postJson($this->api('fiscal-years'), ['name' => 'FY2027', 'starts_on' => '2027-01-01', 'calendar' => '4-4-5'], $this->key())
        ->assertCreated()->json('data');
    $this->periods = array_slice($year['periods'], 0, 12);
});

it('charges one period of life per 4-4-5 period: 1,200.00 over 12 periods is 100.00 each, 1,200.00 in all', function (): void {
    $asset = $this->capitalisedAsset($this->category['id'], ['useful_life_months' => 12], 120000, '2027-01-10');
    $series = [];
    foreach ($this->periods as $i => $p) {
        $preview = $this->asPerson($this->accountant)->getJson($this->api('depreciation-runs/preview?period_id='.$p['id']))->assertOk()->json('data');
        expect($preview['lines'])->toHaveCount(1)->and($preview['lines'][0]['months'])->toBe(1)->and($preview['lines'][0]['catch_up_months'])->toBe(0);
        $run = $this->postedRun($p['starts_on']);
        $series[] = $run['total']['amount'];
    }

    expect($series)->toBe(array_fill(0, 12, 10000));
    $row = $this->asPerson($this->accountant)->getJson($this->api("fixed-assets/{$asset['id']}"))->json('data');
    expect($row['accumulated_depreciation']['amount'])->toBe(120000)->and($row['carrying_amount']['amount'])->toBe(0)
        ->and($row['months_charged'])->toBe(12)->and($row['depreciated_through'])->toBe($this->periods[11]['ends_on']);
});

it('shows the right accumulated depreciation in the asset schedule after the first three 4-4-5 periods', function (): void {
    $this->capitalisedAsset($this->category['id'], ['useful_life_months' => 12], 120000, '2027-01-10');
    foreach (array_slice($this->periods, 0, 3) as $p) {
        $this->postedRun($p['starts_on']);
    }
    $r = $this->asPerson($this->accountant)->getJson($this->api('reports/asset-schedule?from=2027-01-01&to='.$this->periods[2]['ends_on']))->assertOk()->json('data');

    expect($this->periods[2]['ends_on'])->toBe('2027-04-01')
        ->and($r['categories'][0]['accumulated']['charge']['amount'])->toBe(30000)->and($r['categories'][0]['accumulated']['closing']['amount'])->toBe(30000)
        ->and($r['categories'][0]['carrying_closing']['amount'])->toBe(90000);
});

it('starts an asset available mid-period in that period with a full charge, and does not charge it before', function (): void {
    // Available 10 February: inside period 2 (29 January to 25 February), so period 1 owes nothing and period 2 owes a full period.
    $this->capitalisedAsset($this->category['id'], ['useful_life_months' => 12], 120000, '2027-02-10');
    $this->asPerson($this->accountant)->getJson($this->api('depreciation-runs/preview?period_id='.$this->periods[0]['id']))->assertOk()
        ->assertJsonPath('data.blockers', ['nothing_to_depreciate']);
    $run = $this->postedRun($this->periods[1]['starts_on']);

    expect($run['total']['amount'])->toBe(10000)->and($run['lines'][0]['months'])->toBe(1);
});

it('never depreciates in the adjustment period', function (): void {
    $this->capitalisedAsset($this->category['id'], ['useful_life_months' => 12], 120000, '2027-01-10');
    $adjustment = $this->inTenant(fn () => adb('fiscal_periods')->where('company_id', $this->f->companyId)->where('kind', 'adjustment')->where('starts_on', '>=', '2027-01-01')->value('id'));
    $this->asPerson($this->accountant)->postJson($this->api('depreciation-runs'), ['period_id' => $adjustment], $this->key())->assertStatus(422)->assertJsonPath('code', 'depreciation_period_invalid');
});

it('counts an opening asset\'s periods in fiscal periods: stated at the end of period 3, the next run is period 4 only', function (): void {
    $asset = $this->asPerson($this->accountant)->postJson($this->api('fixed-assets'), [
        'name' => 'Old press', 'category_id' => $this->category['id'], 'cost' => 120000, 'acquired_on' => '2027-01-10', 'useful_life_months' => 12,
        'opening_as_of' => '2027-04-01', 'opening_accumulated' => 30000,
    ], $this->key())->assertCreated()->json('data');

    expect($asset['months_charged'])->toBe(3)->and($asset['depreciated_through'])->toBe('2027-04-01');
    $run = $this->postedRun($this->periods[3]['starts_on']);
    expect($run['total']['amount'])->toBe(10000)->and($run['lines'][0]['months'])->toBe(1);
});
