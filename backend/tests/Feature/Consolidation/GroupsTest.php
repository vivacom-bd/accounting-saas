<?php

declare(strict_types=1);

use App\Actions\Consolidation\ManageConsolidationGroup;
use App\Domain\Consolidation\OwnershipHistory;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Consolidation\ConsolidationAccountMap;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Queries\Consolidation\UnmappedBalances;
use Tests\Support\ConsolidationFixture;

beforeEach(function (): void {
    $this->grant('reports.view');
});

it('creates a group with its parent member and the role accounts the engine posts to', function (): void {
    $c = ConsolidationFixture::create();
    $group = $c->group();

    expect($group->presentation_currency)->toBe('USD')
        ->and($c->members['P']->role)->toBe('parent')
        ->and(ConsolidationGroupAccount::query()->where('group_id', $group->id)->whereNotNull('role')->count())->toBe(11)
        ->and(ConsolidationGroupAccount::query()->where('group_id', $group->id)->where('role', 'translation_reserve')->exists())->toBeTrue();
});

it('refuses a presentation currency other than the parent base currency', function (): void {
    $c = ConsolidationFixture::create('USD');

    expect(fn () => app(ManageConsolidationGroup::class)->create($c->actor(), 'G', 'EUR', $c->companies['P']['id']))
        ->toThrow(DomainRuleViolation::class, 'presentation currency');
});

it('adds a subsidiary with a dated ownership history that applies to results after each date', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $member = $c->subsidiary('S', '2026-03-31', 8000);
    app(ManageConsolidationGroup::class)->setOwnership($c->actor(), $member->id, '2026-09-30', 9000, 'Bought 10% more');

    $history = new OwnershipHistory($member->ownerships()->get()->mapWithKeys(fn ($o) => [$o->effective_on->format('Y-m-d') => $o->percent_bp])->all());

    expect($history->onDay('2026-03-31'))->toBeNull()        // the acquisition day itself is measured, not consolidated
        ->and($history->onDay('2026-04-01'))->toBe(8000)
        ->and($history->onDay('2026-09-30'))->toBe(8000)
        ->and($history->onDay('2026-10-01'))->toBe(9000)
        ->and($history->after('2026-09-30'))->toBe(9000);
});

it('refuses a member twice, an ownership before acquisition, and a mapping to a group account of another type', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $member = $c->subsidiary('S', '2026-03-31', 8000);
    $action = app(ManageConsolidationGroup::class);

    expect(fn () => $c->subsidiary('S', '2026-03-31', 8000))->toThrow(DomainRuleViolation::class, 'already in the group')
        ->and(fn () => $action->setOwnership($c->actor(), $member->id, '2026-01-31', 5000, null))->toThrow(DomainRuleViolation::class, 'before the acquisition')
        ->and(fn () => $action->mapAccounts($c->actor(), $member->id, [['account_id' => $c->companies['S']['accounts']['1000'], 'group_account_id' => $c->groupAccounts['4000']]]))
        ->toThrow(DomainRuleViolation::class, 'is asset but');
});

it('lists accounts with a balance and no mapping, and nothing once everything is mapped', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2026-03-31', 10000);
    $c->post('S', '2026-02-10', [['1000', 5000, 0], ['3000', 0, 5000]]);
    $c->post('S', '2026-05-10', [['1000', 700, 0], ['4000', 0, 700]]);
    $c->mapAll('P');
    // Only the capital account of S is mapped: cash, revenue are not.
    app(ManageConsolidationGroup::class)->mapAccounts($c->actor(), $c->members['S']->id, [['account_id' => $c->companies['S']['accounts']['3000'], 'group_account_id' => $c->groupAccounts['3000']]]);

    $unmapped = app(UnmappedBalances::class)($c->group->id, '2026-04-01', '2026-06-30');
    $byCode = collect($unmapped)->keyBy('code');

    expect($byCode->pluck('code')->sort()->values()->all())->toBe(['1000', '4000'])
        ->and($byCode['1000']->net)->toBe(5700)
        ->and($byCode['4000']->net)->toBe(-700)
        ->and($byCode['4000']->movementCredit)->toBe(700);

    $c->mapAll('S');
    expect(app(UnmappedBalances::class)($c->group->id, '2026-04-01', '2026-06-30'))->toBe([])
        ->and(ConsolidationAccountMap::query()->where('member_id', $c->members['S']->id)->count())->toBe(count(ConsolidationFixture::CHART));
});

it('refuses a person without the organisation-level right or without view rights in a member', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $this->grant();   // nobody holds reports.view in any company

    expect(fn () => $c->subsidiary('S', '2026-03-31', 8000))->toThrow(DomainRuleViolation::class, 'view rights in every member');
});
