<?php

declare(strict_types=1);

use App\Actions\Consolidation\ManageConsolidationRun;
use App\Actions\Consolidation\ManageIntercompany;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConsolidationFixture;
use Tests\Support\LedgerFixture;

/*
 * The intercompany tables (plan 3c.29c) as the app role sees them: another tenant's contact links and matching reports are
 * invisible, cannot be changed, and cannot be written under its id; the mapping's flags are checked by the database too.
 */

beforeEach(function (): void {
    $this->grant('reports.view');
});

it('isolates the intercompany contact links and matching reports between tenants', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $c->mapAll('S');
    $contact = (string) Str::uuid7();
    DB::table('contacts')->insert(['id' => $contact, 'tenant_id' => $c->f->tenantId, 'company_id' => $c->companies['P']['id'], 'kind' => 'customer', 'code' => 'S1', 'name' => 'Company S', 'default_currency' => 'USD']);
    app(ManageIntercompany::class)->set($c->actor(), $c->members['P']->id, [
        ['account_id' => $c->companies['P']['accounts']['1200'], 'ic_class' => 'balance', 'counterparty_member_id' => $c->members['S']->id],
    ], [['contact_id' => $contact, 'counterparty_member_id' => $c->members['S']->id]]);
    $c->post('P', '2026-01-10', [['1200', 100000, 0], ['4100', 0, 100000]]);
    $run = app(ManageConsolidationRun::class)->create($c->actor(), $c->group->id, '2026-01-01', '2026-01-31');
    $tenantA = $c->f->tenantId;

    LedgerFixture::create(slug: 'beta');
    $context = app(TenantContext::class);
    foreach (['consolidation_ic_contacts', 'consolidation_ic_matches'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} leaks another tenant's rows");
        $context->run($tenantA, fn () => expect(DB::table($table)->count())->toBeGreaterThan(0, "{$table} has no row to test"));
    }
    expect(DB::table('consolidation_ic_contacts')->update(['counterparty_member_id' => $c->members['P']->id]))->toBe(0)
        ->and(DB::table('consolidation_ic_matches')->update(['reason' => 'x']))->toBe(0)
        ->and(fn () => DB::table('consolidation_ic_matches')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantA, 'run_id' => $run->id, 'member_a_id' => $c->members['P']->id, 'member_b_id' => $c->members['S']->id,
            'ic_class' => 'loan', 'side_a' => 0, 'side_b' => 0, 'difference' => 0, 'matched' => 0, 'exceeds_tolerance' => false,
        ]))->toThrow(QueryException::class, 'row-level security');
});

it('checks the intercompany flags on the mapping in the database', function (): void {
    $c = ConsolidationFixture::create();
    $c->company('S', 'USD');
    $c->group();
    $c->subsidiary('S', '2025-12-31', 10000);
    $c->mapAll('P');
    $map = fn (string $code) => DB::table('consolidation_account_maps')->where('account_id', $c->companies['P']['accounts'][$code]);

    expect(fn () => $map('1200')->update(['ic_class' => 'gift']))->toThrow(QueryException::class, 'ic_class_check')
        ->and(fn () => $map('1200')->update(['counterparty_member_id' => $c->members['S']->id]))->toThrow(QueryException::class, 'ic_counterparty_check')
        ->and(fn () => $map('1200')->update(['ic_class' => 'balance', 'counterparty_member_id' => $c->members['P']->id]))->toThrow(QueryException::class, 'ic_self_check')
        ->and(fn () => $map('1200')->update(['ic_class' => 'balance', 'net_investment' => true]))->toThrow(QueryException::class, 'net_investment_check');
});
