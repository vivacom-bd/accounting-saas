<?php

declare(strict_types=1);

use App\Actions\Consolidation\ManageConsolidationRun;
use App\Actions\Consolidation\ManageMemberOpening;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConsolidationFixture;
use Tests\Support\LedgerFixture;

/*
 * `consolidation_member_openings` as the app role sees it: editable until a run using it is approved, then locked by the
 * trigger whatever the code does; never deleted; invisible and unwritable across tenants.
 */

beforeEach(function (): void {
    $this->grant('reports.view');
});

function openedGroup(): array
{
    $c = ConsolidationFixture::create('USD');
    $c->company('K', 'KES');
    $c->fiscalYear('K', '2025-01-01', 'FY2025');
    $c->group();
    $c->subsidiary('K', '2025-06-30', 10000);
    $c->mapAll('P');
    $c->mapAll('K');
    $c->rate('P', 'KES', 'USD', '2025-12-31', '0.0080');
    $c->post('K', '2025-12-31', [['1000', 1000000, 0], ['3000', 0, 1000000]]);
    $opening = app(ManageMemberOpening::class)->set($c->actor(), $c->members['K']->id, '2025-12-31', [$c->groupAccounts['3000'] => -8000], 0, 'Earlier consolidation');

    return [$c, $opening];
}

it('lets an opening change until a run using it is approved, then refuses every change and every delete', function (): void {
    [$c, $opening] = openedGroup();

    expect(DB::table('consolidation_member_openings')->where('id', $opening->id)->update(['reason' => 'Corrected']))->toBe(1)
        ->and(fn () => DB::table('consolidation_member_openings')->where('id', $opening->id)->delete())->toThrow(QueryException::class, 'never deleted');

    $runs = app(ManageConsolidationRun::class);
    $runs->approve($c->person(), $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31')->id);

    expect(DB::table('consolidation_member_openings')->where('id', $opening->id)->value('locked_by_run_id'))->not->toBeNull()
        ->and(fn () => DB::table('consolidation_member_openings')->where('id', $opening->id)->update(['translation_reserve' => 1]))->toThrow(QueryException::class, 'is locked')
        ->and(fn () => DB::table('consolidation_member_openings')->where('id', $opening->id)->update(['locked_by_run_id' => null, 'locked_at' => null]))->toThrow(QueryException::class, 'is locked')
        ->and(fn () => DB::table('consolidation_member_openings')->where('id', $opening->id)->delete())->toThrow(QueryException::class, 'never deleted');
});

it('allows only one change to a locked opening: unlocking it, once its run is reversed', function (): void {
    [$c, $opening] = openedGroup();
    $runs = app(ManageConsolidationRun::class);
    $run = $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');
    $computedId = $run->id;
    $table = fn () => DB::table('consolidation_member_openings')->where('id', $opening->id);

    // Locking needs an approved run.
    expect(fn () => $table()->update(['locked_by_run_id' => $computedId, 'locked_at' => now()]))->toThrow(QueryException::class, 'approved run');

    $runs->approve($c->person(), $run->id);
    DB::table('consolidation_runs')->where('id', $run->id)->update(['status' => 'reversed', 'reversed_at' => now(), 'reason' => 'test']);

    // Unlocking together with another change is still refused; the bare unlock is allowed.
    expect(fn () => $table()->update(['locked_by_run_id' => null, 'locked_at' => null, 'translation_reserve' => 5]))->toThrow(QueryException::class, 'is locked')
        ->and(fn () => $table()->update(['reason' => 'x']))->toThrow(QueryException::class, 'is locked')
        ->and($table()->update(['locked_by_run_id' => null, 'locked_at' => null, 'lock_version' => 9]))->toBe(1)
        ->and($table()->update(['reason' => 'Editable again']))->toBe(1);
});

it('isolates openings between tenants', function (): void {
    [$c, $opening] = openedGroup();
    $tenantA = $c->f->tenantId;

    $b = LedgerFixture::create(slug: 'beta');
    expect(app(TenantContext::class)->id())->toBe($b->tenantId)
        ->and(DB::table('consolidation_member_openings')->count())->toBe(0)
        ->and(DB::table('consolidation_member_openings')->where('id', $opening->id)->update(['reason' => 'hacked']))->toBe(0)
        ->and(fn () => DB::table('consolidation_member_openings')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantA, 'group_id' => $c->group->id, 'member_id' => $c->members['P']->id, 'as_of' => '2025-12-31', 'reason' => 'x',
        ]))->toThrow(QueryException::class, 'row-level security');

    app(TenantContext::class)->run($tenantA, fn () => expect(DB::table('consolidation_member_openings')->count())->toBe(1));
});
