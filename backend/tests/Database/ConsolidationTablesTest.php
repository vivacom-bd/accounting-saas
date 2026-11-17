<?php

declare(strict_types=1);

use App\Actions\Consolidation\Inputs\EntryDraftInput;
use App\Actions\Consolidation\ManageConsolidationEntry;
use App\Actions\Consolidation\ManageConsolidationGroup;
use App\Actions\Consolidation\ManageConsolidationRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConsolidationFixture;
use Tests\Support\LedgerFixture;

/*
 * The consolidation run and ledger tables (plan 3c.29b) as the app role sees them: another tenant's rows are invisible,
 * cannot be changed, and cannot be written under its id.
 */

const CONSOLIDATION_RUN_TABLES = [
    'consolidation_runs', 'consolidation_run_members', 'consolidation_run_lines', 'consolidation_rate_overrides',
    'consolidation_sequences', 'consolidation_entries', 'consolidation_entry_lines',
];

beforeEach(function (): void {
    $this->grant('reports.view');
});

it('isolates the consolidation run and ledger tables between tenants', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2026-01-03', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $checker = $c->person();
    $c->post('S', '2026-01-02', [['1000', 500, 0], ['3000', 0, 500]]);
    app(ManageConsolidationGroup::class)->setRateOverride($c->actor(), $c->group->id, 'EUR', '2026-01-01', '2026-01-31', '1.1', 'Test');
    $entries = app(ManageConsolidationEntry::class);
    $entries->approve($checker, $entries->draftManual($c->actor(), new EntryDraftInput($c->group->id, 'manual', '2026-01-10', [
        ['account_id' => $c->groupAccounts['5000'], 'net' => 10], ['account_id' => $c->groupAccounts['2000'], 'net' => -10],
    ], 'Accrual'))->id);
    $runs = app(ManageConsolidationRun::class);
    $runId = $runs->approve($checker, $runs->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31')->id)->id;
    $tenantA = $c->f->tenantId;

    $b = LedgerFixture::create(slug: 'beta');
    $context = app(TenantContext::class);
    expect($context->id())->toBe($b->tenantId);

    foreach (CONSOLIDATION_RUN_TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} leaks another tenant's rows");
        $context->run($tenantA, fn () => expect(DB::table($table)->count())->toBeGreaterThan(0, "{$table} has no row to test"));
    }
    expect(DB::table('consolidation_runs')->where('id', $runId)->update(['reason' => 'hacked']))->toBe(0)
        ->and(DB::table('consolidation_rate_overrides')->update(['rate' => '9']))->toBe(0)
        ->and(fn () => DB::table('consolidation_runs')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantA, 'group_id' => $c->group->id, 'period_from' => '2026-02-01', 'period_to' => '2026-02-28', 'currency' => 'USD',
        ]))->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => DB::table('consolidation_sequences')->insert([
            'group_id' => $c->group->id, 'tenant_id' => $tenantA, 'year' => 2030, 'next_value' => 1,
        ]))->toThrow(QueryException::class, 'row-level security');
});
