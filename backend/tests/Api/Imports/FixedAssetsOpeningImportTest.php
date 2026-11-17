<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\AssetApi;
use Tests\Support\ImportsApi;
use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;

/*
 * Plan 3c.15c: opening assets in bulk (kind fixed_assets_opening). One row per asset, checked with CreateFixedAsset's rules,
 * previewed without writing, and applied all or nothing through its opening path. Nothing posts. USD has two decimals.
 */

uses(MakesApiCalls::class, TradeApi::class, AssetApi::class, ImportsApi::class);

const ASSET_MAP = [
    'name' => 'Name', 'category' => 'Category', 'acquired_on' => 'Acquisition date', 'available_from' => 'Available-for-use date', 'cost' => 'Cost',
    'accumulated' => 'Accumulated depreciation', 'residual' => 'Residual value', 'method' => 'Method', 'life_months' => 'Useful life (months)',
];

const ASSET_CSV = <<<'CSV'
Name,Category,Acquisition date,Available-for-use date,Cost,Accumulated depreciation,Residual value,Method,Useful life (months)
Lathe,Equipment,2025-01-15,,120000.00,12000.00,0,,
Press,equipment,2025-03-01,2025-04-01,60000.00,5000.00,6000.00,straight line,60
Truck,Equipment,2025-06-10,,30000.00,0,,declining balance,

CSV;

beforeEach(function (): void {
    $this->setUpAssets();
    $this->category = $this->assetCategory(['default_declining_rate_bp' => 2000]);
    $this->assetCount = fn (): int => $this->inTenant(fn () => DB::table('fixed_assets')->count());
});

it('lists the kind, with its permission and all-or-nothing, and serves a template that maps itself', function (): void {
    $kinds = array_column($this->asPerson($this->owner)->getJson($this->api('imports/kinds'))->assertOk()->json('data'), null, 'kind');
    expect($kinds)->toHaveKey('fixed_assets_opening')->and($kinds['fixed_assets_opening']['permission'])->toBe('fixed_assets.manage')
        ->and($kinds['fixed_assets_opening']['all_or_nothing'])->toBeTrue();

    $csv = $this->asPerson($this->owner)->get($this->api('imports/kinds/fixed_assets_opening/template'))->assertOk()->streamedContent();
    expect(str_getcsv(trim($csv)))->toBe(array_column($kinds['fixed_assets_opening']['fields'], 'label'))
        ->and(str_getcsv(trim($csv)))->toContain('Name', 'Category', 'Acquisition date', 'Available-for-use date', 'Cost', 'Accumulated depreciation', 'Method', 'Useful life (months)', 'Residual value');
});

it('previews every row with the totals and writes nothing', function (): void {
    $import = $this->previewed('fixed_assets_opening', ASSET_CSV, ASSET_MAP, ['as_of' => '2026-01-01']);

    expect($import['rows_valid'])->toBe(3)->and($import['rows_invalid'])->toBe(0)->and($import['errors'])->toBe([])->and($import['all_or_nothing'])->toBeTrue()
        ->and($import['needs_signature'])->toBeFalse()
        ->and($import['result']['preview'])->toMatchArray(['as_of' => '2026-01-01', 'assets' => 3, 'total_cost' => 21000000, 'total_accumulated_depreciation' => 1700000, 'total_carrying_amount' => 19300000]);
    expect(($this->assetCount)())->toBe(0)->and($this->rowCount('journals'))->toBe(0);
});

it('names what is wrong on each row, and refuses to apply while any row is wrong', function (): void {
    $csv = <<<'CSV'
Name,Category,Acquisition date,Available-for-use date,Cost,Accumulated depreciation,Residual value,Method,Useful life (months)
Lathe,Equipment,2025-01-15,,120000.00,12000.00,0,,
,Equipment,2025-01-15,,100.00,0,,,
Boat,Ships,2025-01-15,,100.00,0,,,
Van,Equipment,15/01/2025,,100.00,0,,,
Saw,Equipment,2025-01-15,2024-12-01,100.00,0,,,
Drill,Equipment,2025-01-15,,100.00,120000.00,,,
Jack,Equipment,2025-01-15,,100.00,0,,rocket,
Hoist,Equipment,2025-01-15,,100.00,0,200.00,,
Crane,Equipment,2025-01-15,,abc,0,,,
Rack,Equipment,2025-01-15,,100.00,0,,declining balance,

CSV;
    $import = $this->previewed('fixed_assets_opening', $csv, ASSET_MAP, ['as_of' => '2026-01-01']);
    $rows = $this->rowsOf($import['id']);
    $code = fn (int $n): string => $rows[$n]['errors'][0]['code'];

    expect($rows[2]['status'])->toBe('valid')
        ->and($code(3))->toBe('required')->and($rows[3]['errors'][0]['field'])->toBe('name')
        ->and($code(4))->toBe('name_not_found')->and($rows[4]['errors'][0]['field'])->toBe('category')
        ->and($code(5))->toBe('invalid_date')->and($rows[5]['errors'][0]['field'])->toBe('acquired_on')
        ->and($code(6))->toBe('asset_dates_invalid')
        ->and($code(7))->toBe('asset_opening_accumulated_invalid')
        ->and($code(8))->toBe('method_invalid')->and($rows[8]['errors'][0]['field'])->toBe('method')
        ->and($code(9))->toBe('asset_residual_invalid')
        ->and($code(10))->toBe('invalid_amount')->and($rows[10]['errors'][0]['field'])->toBe('cost')
        ->and($rows[11]['status'])->toBe('valid');
    expect($import['rows_invalid'])->toBe(8);

    $this->requestApply($import['id'], signing: false)->assertStatus(422)->assertJsonPath('code', 'import_has_errors');
    expect(($this->assetCount)())->toBe(0);
});

it('enters the assets in the register with the depreciation to date worked out from the periods, and posts nothing', function (): void {
    $import = $this->previewed('fixed_assets_opening', ASSET_CSV, ASSET_MAP, ['as_of' => '2026-01-01']);
    $this->requestApply($import['id'], signing: false)->assertStatus(202);
    expect(($this->assetCount)())->toBe(0);
    $this->runJob($import['id']);

    $done = $this->importOf($import['id']);
    expect($done['status'])->toBe('completed')->and($done['rows_applied'])->toBe(3)
        ->and($done['result']['applied'])->toMatchArray(['assets' => 3, 'first_number' => 'FA-00001', 'last_number' => 'FA-00003']);
    $assets = $this->inTenant(fn () => DB::table('fixed_assets')->orderBy('number')->get());
    expect($assets)->toHaveCount(3)->and($assets->pluck('name')->all())->toBe(['Lathe', 'Press', 'Truck'])
        ->and($assets->every(fn ($a) => (bool) $a->is_opening && $a->category_id === $this->category['id'] && $a->status === 'active'))->toBeTrue()
        ->and((int) $assets[0]->cost)->toBe(12000000)->and((int) $assets[0]->accumulated_depreciation)->toBe(1200000)->and((int) $assets[0]->opening_accumulated)->toBe(1200000)
        ->and($assets[0]->opening_as_of)->toBe('2026-01-01')->and($assets[0]->acquired_on)->toBe('2025-01-15')->and($assets[0]->available_from)->toBe('2025-01-15')
        ->and((int) $assets[0]->months_charged)->toBe(12)->and($assets[0]->depreciated_through)->toBe('2025-12-31')
        ->and((int) $assets[1]->residual)->toBe(600000)->and($assets[1]->available_from)->toBe('2025-04-01')->and((int) $assets[1]->useful_life_months)->toBe(60)
        ->and((int) $assets[1]->months_charged)->toBe(9)
        ->and($assets[2]->method)->toBe('declining_balance')->and((int) $assets[2]->declining_rate_bp)->toBe(2000)->and((int) $assets[2]->months_charged)->toBe(7);
    expect($this->rowCount('journals'))->toBe(0)
        ->and($this->inTenant(fn () => DB::table('asset_movements')->where('kind', 'opening')->count()))->toBe(3);

    // Running the job again, or asking again, adds nothing.
    $this->runJob($import['id']);
    expect(($this->assetCount)())->toBe(3);
});

it('is all or nothing: if the world changed after the preview, no asset is created', function (): void {
    $import = $this->previewed('fixed_assets_opening', ASSET_CSV, ASSET_MAP, ['as_of' => '2026-01-01']);
    $this->requestApply($import['id'], signing: false)->assertStatus(202);
    $this->inTenant(fn () => DB::table('asset_categories')->where('id', $this->category['id'])->update(['is_active' => false]));
    $this->runJob($import['id']);

    $rows = $this->rowsOf($import['id']);
    expect(($this->assetCount)())->toBe(0)->and(collect($rows)->pluck('status')->unique()->all())->toBe(['failed'])
        ->and($rows[2]['errors'][0]['code'])->toBe('asset_category_inactive');
    $this->inTenant(fn () => expect(DB::table('sequences')->where('sequence_key', 'doc:fixed_asset:all')->count())->toBe(0));
});

it('needs the fixed assets permission', function (): void {
    $this->upload('fixed_assets_opening', ASSET_CSV, ['as_of' => '2026-01-01'], by: $this->assistant)->assertForbidden();
    $this->upload('fixed_assets_opening', ASSET_CSV, ['as_of' => '2026-01-01'], by: $this->accountant)->assertCreated();
});
