<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssetApi;
use Tests\Support\LedgerFixture;
use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;

uses(MakesApiCalls::class, TradeApi::class, AssetApi::class);

/*
 * Plan 3c.15c: the fixed asset tables as the read-only report role (ledgerly_report) sees them. Under forced row level
 * security it can SELECT a tenant's own rows, sees nothing of another tenant's, and cannot write.
 */

const FIXED_ASSET_TABLES = [
    'asset_categories', 'fixed_assets', 'asset_movements', 'depreciation_runs', 'depreciation_run_lines',
    'asset_transactions', 'asset_transaction_lines', 'asset_tax_classes',
];

it('lists every fixed asset table, all forced to row level security', function (): void {
    $rows = DB::connection('pgsql_owner')->select(
        "SELECT relname, relforcerowsecurity FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relname = ANY (?::text[])",
        ['{'.implode(',', FIXED_ASSET_TABLES).'}'],
    );
    expect(collect($rows)->pluck('relname')->sort()->values()->all())->toBe(collect(FIXED_ASSET_TABLES)->sort()->values()->all())
        ->and(collect($rows)->every(fn ($r) => $r->relforcerowsecurity))->toBeTrue();
});

it('lets the report role read the fixed asset tables of its own tenant, and nothing of another, and not write', function (): void {
    $this->setUpAssets();
    $category = $this->assetCategory();
    $asset = $this->capitalisedAsset($category['id']);
    $this->postedRun('2026-03-15');
    $this->postedRun('2026-04-15');
    $this->asPerson($this->accountant)->postJson($this->api('asset-transactions'), [
        'kind' => 'disposal', 'tx_date' => '2026-05-15', 'asset_id' => $asset['id'],
        'proceeds' => 100, 'proceeds_account_id' => $this->f->account('1000'), 'reason' => 'Sold',
    ], $this->key())->assertCreated();
    $this->inTenant(fn () => DB::table('asset_tax_classes')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->f->tenantId, 'company_id' => $this->f->companyId,
        'code' => 'C1', 'name' => 'Class 1', 'annual_rate_bp' => 1000,
    ]));
    $mine = $this->f->tenantId;
    $report = DB::connection('pgsql_report');
    $context = app(TenantContext::class);

    $context->run($mine, function () use ($report): void {
        foreach (FIXED_ASSET_TABLES as $table) {
            expect((int) $report->table($table)->count())->toBeGreaterThan(0, "{$table} should be readable by the report role");
        }
    });

    $other = LedgerFixture::create(slug: 'other');
    $context->run($other->tenantId, function () use ($report): void {
        foreach (FIXED_ASSET_TABLES as $table) {
            expect((int) $report->table($table)->count())->toBe(0, "{$table} leaks across tenants");
        }
    });

    $context->clear();
    foreach (FIXED_ASSET_TABLES as $table) {
        expect((int) $report->table($table)->count())->toBe(0, "{$table} must fail closed without a tenant");
    }

    $context->run($mine, function () use ($report): void {
        foreach (FIXED_ASSET_TABLES as $table) {
            expect(fn () => $report->table($table)->update(['tenant_id' => DB::raw('tenant_id')]))->toThrow(QueryException::class);
        }
    });
});
