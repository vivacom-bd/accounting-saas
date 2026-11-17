<?php

declare(strict_types=1);

use App\Actions\Consolidation\ManageConsolidationRun;
use App\Actions\Consolidation\ManageMemberOpening;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Audit\AuditEvent;
use App\Models\Consolidation\ConsolidationMemberOpening;
use App\Models\Consolidation\ConsolidationRunLine;
use App\Models\Platform\User;
use Tests\Support\ConsolidationFixture;

/*
 * A foreign subsidiary the group held before its first run in Ledgerly (owner's decision 2026-11-17): the accountant
 * enters once its equity at historical rates and the translation reserve, in the group currency, as of the date of the
 * last consolidation done elsewhere. The first run starts the day after; once a run using it is approved it is locked.
 */

beforeEach(function (): void {
    $this->grant('reports.view');
});

/**
 * A USD parent; a KES subsidiary acquired on 30 Jun 2025 whose books at 31 Dec 2025 hold cash 106,000,000, capital
 * −100,000,000 and a 2025 profit of 6,000,000 (retained earnings). Rates (USD per KES): 31 Dec 2025 0.0080, 31 Mar 2026 0.0075.
 * In the quarter: revenue 10,000,000 on 15 Feb.
 */
function heldGroup(): array
{
    $c = ConsolidationFixture::create('USD');
    $c->company('K', 'KES');
    $c->fiscalYear('K', '2025-01-01', 'FY2025');
    $c->group();
    $c->subsidiary('K', '2025-06-30', 10000);
    $c->mapAll('P');
    $c->mapAll('K');
    $c->rate('P', 'KES', 'USD', '2025-12-31', '0.0080');
    $c->rate('P', 'KES', 'USD', '2026-03-31', '0.0075');
    $c->post('P', '2026-01-02', [['1000', 1000000, 0], ['3000', 0, 1000000]]);
    $c->post('K', '2025-12-31', [['1000', 106000000, 0], ['3000', 0, 100000000], ['4000', 0, 6000000]]);
    $c->post('K', '2026-02-15', [['1000', 10000000, 0], ['4000', 0, 10000000]]);

    return [$c, $c->person()];
}

/** The opening from the earlier consolidation: capital −800,000, retained earnings −50,000, reserve +2,000 (USD minor units). */
function enterOpening(ConsolidationFixture $c, $actor = null, string $asOf = '2025-12-31', ?array $equity = null): ConsolidationMemberOpening
{
    return app(ManageMemberOpening::class)->set($actor ?? $c->actor(), $c->members['K']->id, $asOf,
        $equity ?? [$c->groupAccounts['3000'] => -800000, $c->groupAccounts['G3000'] => -50000], 2000, 'Group accounts FY2025, signed off');
}

function translatedK(ConsolidationFixture $c, string $runId): array
{
    $codes = array_flip($c->groupAccounts);
    $out = [];
    foreach (ConsolidationRunLine::query()->where('run_id', $runId)->where('kind', 'translated')->where('member_id', $c->members['K']->id)->get() as $l) {
        $out[(string) $codes[$l->group_account_id]] = $l->amount;
    }
    ksort($out);

    return $out;
}

it('runs from the entered opening, continues the reserve from it, and locks it when a run using it is approved', function (): void {
    [$c, $checker] = heldGroup();
    $opening = enterOpening($c);
    $runs = app(ManageConsolidationRun::class);

    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-03-31');

    // Average: 0.0080 for 89 days, 0.0075 for 1 → 0.7195 ÷ 90 = 0.00799444. Revenue −10,000,000 → −79,944.
    // Cash 116,000,000 × 0.0075 = 870,000. Capital −800,000 and retained earnings −50,000 as entered.
    // Reserve = −(870,000 − 800,000 − 50,000 − 79,944) = 59,944; the quarter's difference 59,944 − 2,000 = 57,944.
    expect(translatedK($c, $run->id))->toBe(['1000' => 870000, '3000' => -800000, '4000' => -79944, 'G3000' => -50000, 'G3200' => 59944])
        ->and($run->summary['translation_difference'])->toBe(57944)
        ->and($opening->fresh()->locked_by_run_id)->toBeNull();

    // Still editable before approval; a changed opening makes the computed run stale.
    enterOpening($c, equity: [$c->groupAccounts['3000'] => -810000, $c->groupAccounts['G3000'] => -40000]);
    expect(fn () => $runs->approve($checker, $run->id))->toThrow(DomainRuleViolation::class, 'changed after this run was computed');
    $runs->void($c->actor(), $run->id, 'Opening corrected');
    enterOpening($c);
    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-03-31');

    $runs->approve($checker, $run->id);

    expect($opening->fresh()->locked_by_run_id)->toBe($run->id)
        ->and(fn () => enterOpening($c))->toThrow(DomainRuleViolation::class, 'locked');
});

it('unlocks the opening when the run that locked it is reversed, and locks it again on the next approval', function (): void {
    [$c, $checker] = heldGroup();
    $opening = enterOpening($c);
    $runs = app(ManageConsolidationRun::class);
    $first = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-03-31');
    $runs->approve($checker, $first->id);

    $runs->reverse($checker, $first->id, 'Opening reserve was wrong');

    expect($opening->fresh()->locked_by_run_id)->toBeNull()->and($opening->fresh()->locked_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'consolidation_opening.unlocked')->where('subject_id', $opening->id)->firstOrFail()->context['run_id'] ?? null)->toBe($first->id);

    // Editable again; the next approved run locks the corrected opening.
    $corrected = app(ManageMemberOpening::class)->set($c->actor(), $c->members['K']->id, '2025-12-31',
        [$c->groupAccounts['3000'] => -800000, $c->groupAccounts['G3000'] => -50000], 3000, 'Reserve corrected');
    $second = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-03-31');
    $runs->approve($checker, $second->id);

    expect($corrected->fresh()->translation_reserve)->toBe(3000)
        ->and($corrected->fresh()->locked_by_run_id)->toBe($second->id)
        ->and(fn () => enterOpening($c))->toThrow(DomainRuleViolation::class, 'locked');
});

it('starts the first run the day after the opening date, and blocks without acquisition history or an opening', function (): void {
    [$c] = heldGroup();
    $runs = app(ManageConsolidationRun::class);

    expect(fn () => $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-03-31'))->toThrow(DomainRuleViolation::class, 'cannot be computed');

    enterOpening($c);
    try {
        $runs->create($c->actor(), $c->group->id, '2026-02-01', '2026-03-31');
        $this->fail('expected a block');
    } catch (DomainRuleViolation $e) {
        expect(collect($e->violations)->firstWhere('code', 'consolidation_opening_date')?->message)->toContain('2026-01-01');
    }
});

it('blocks a run when the opening does not cover every equity account the books held at its date', function (): void {
    [$c] = heldGroup();
    enterOpening($c, equity: [$c->groupAccounts['3000'] => -800000]);   // retained earnings of 6,000,000 KES left out

    try {
        app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-03-31');
        $this->fail('expected a block');
    } catch (DomainRuleViolation $e) {
        expect(collect($e->violations)->pluck('code')->all())->toContain('consolidation_opening_incomplete');
    }
});

it('refuses an opening that is not needed or not well formed', function (): void {
    [$c] = heldGroup();
    $set = fn (array $equity, string $asOf = '2025-12-31', ?string $member = null) => app(ManageMemberOpening::class)
        ->set($c->actor(), $member ?? $c->members['K']->id, $asOf, $equity, 0, 'x');

    expect(fn () => $set([$c->groupAccounts['3000'] => -1], '2025-06-29'))->toThrow(DomainRuleViolation::class, 'before the acquisition')
        ->and(fn () => $set([$c->groupAccounts['1000'] => -1]))->toThrow(DomainRuleViolation::class, 'equity account')
        ->and(fn () => $set([$c->groupAccounts['G3200'] => -1]))->toThrow(DomainRuleViolation::class, 'equity account')
        ->and(fn () => $set([$c->groupAccounts['3000'] => -1], member: $c->members['P']->id))->toThrow(DomainRuleViolation::class, 'presentation currency');
});

it('sets and reads the opening over the API, for people who manage the group', function (): void {
    [$c] = heldGroup();
    $owner = User::query()->findOrFail($c->f->userId);
    $url = "/api/v1/consolidation/members/{$c->members['K']->id}/opening";

    $this->actingAs($owner)->getJson($url)->assertNotFound();
    $this->putJson($url, [
        'as_of' => '2025-12-31', 'translation_reserve' => 2000, 'reason' => 'Group accounts FY2025',
        'equity' => [['group_account_id' => $c->groupAccounts['3000'], 'amount' => -800000], ['group_account_id' => $c->groupAccounts['G3000'], 'amount' => -50000]],
    ])->assertCreated()->assertJsonPath('data.as_of', '2025-12-31')->assertJsonPath('data.translation_reserve.amount', 2000)
        ->assertJsonPath('data.locked', false)->assertJsonPath('data.can.manage', true);
    $this->getJson($url)->assertOk()->assertJsonPath('data.equity.0.amount.currency', 'USD')->assertJsonCount(2, 'data.equity');

    $member = User::query()->findOrFail($c->person('viewer', 'member')->id);
    $this->actingAs($member)->putJson($url, ['as_of' => '2025-12-31', 'translation_reserve' => 0, 'reason' => 'x', 'equity' => []])->assertForbidden();
});
