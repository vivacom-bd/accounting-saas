<?php

declare(strict_types=1);

/*
 * Plan 3c.15c: an asset capitalised from a bill or journal that was later reversed, voided or credited in full is flagged, not
 * changed. The register shows a warning, depreciation runs list it, and the close checklist warns. Nothing is reversed
 * automatically: the accountant decides (dispose or write the asset off, or reverse its depreciation first).
 */

beforeEach(function (): void {
    $this->setUpAssets();
    $this->category = $this->assetCategory();
    $this->warning = fn (string $id) => $this->asPerson($this->accountant)->getJson($this->api("fixed-assets/{$id}"))->assertOk()->json('data.source_warning');
    $this->reverseJournal = fn (string $id) => $this->asPerson($this->owner, signing: true)->postJson($this->api("journals/{$id}/reverse"), [], $this->key())->assertSuccessful();
    $this->billAsset = function (array $extra = []): array {
        $bill = $this->postedDoc('bills', $this->t->body($this->t->supplier, [[250000, '2', null, '0', '1500']], '2026-03-02'));
        $line = $this->inTenant(fn () => (string) adb('invoice_lines')->where('invoice_id', $bill['id'])->value('id'));
        $asset = $this->asPerson($this->accountant)->postJson($this->api('fixed-assets'), [
            'name' => 'Two lathes', 'category_id' => $this->category['id'], 'source_type' => 'bill_line', 'source_id' => $line, 'useful_life_months' => 60,
        ], $this->key())->assertCreated()->json('data');

        return [$bill, $asset];
    };
    $this->debitNote = fn (array $bill, int $price) => $this->postedDoc('bills', $this->t->body($this->t->supplier, [[$price, '1', null, '0', '1500']], '2026-03-10', ['kind' => 'debit_note', 'related_invoice_id' => $bill['id']]));
});

it('shows no warning while the source stands', function (): void {
    $asset = $this->capitalisedAsset($this->category['id']);
    [, $billAsset] = ($this->billAsset)();

    expect($asset['source_warning'])->toBeNull()->and($billAsset['source_warning'])->toBeNull();
});

it('flags an asset whose source journal was reversed, and does not touch the asset', function (): void {
    [$journalId, $line] = $this->assetPurchaseJournal();
    $asset = $this->asPerson($this->accountant)->postJson($this->api('fixed-assets'), [
        'name' => 'Press', 'category_id' => $this->category['id'], 'source_type' => 'journal_line', 'source_id' => $line, 'useful_life_months' => 120,
    ], $this->key())->assertCreated()->json('data');
    ($this->reverseJournal)($journalId);

    $warning = ($this->warning)($asset['id']);
    expect($warning)->toMatchArray(['code' => 'source_reversed', 'document_type' => 'journal', 'document_id' => $journalId])
        ->and($warning['message'])->toContain('reversed')
        ->and($warning['document_number'])->not->toBeNull();
    $list = collect($this->asPerson($this->accountant)->getJson($this->api('fixed-assets'))->json('data'))->firstWhere('id', $asset['id']);
    expect($list['source_warning']['code'])->toBe('source_reversed');
    $after = $this->asPerson($this->accountant)->getJson($this->api("fixed-assets/{$asset['id']}"))->json('data');
    expect($after['status'])->toBe('active')->and($after['cost']['amount'])->toBe(12000000);
});

it('flags an asset whose source bill was reversed', function (): void {
    [$bill, $asset] = ($this->billAsset)();
    $this->asPerson($this->owner, signing: true)->postJson($this->api("bills/{$bill['id']}/reverse"), [], $this->key())->assertOk();

    expect(($this->warning)($asset['id']))->toMatchArray(['code' => 'source_reversed', 'document_type' => 'bill', 'document_id' => $bill['id'], 'document_number' => $bill['number']]);
});

it('flags a bill credited in full by debit notes, and not one credited in part', function (): void {
    [$bill, $asset] = ($this->billAsset)();
    ($this->debitNote)($bill, 200000);
    expect(($this->warning)($asset['id']))->toBeNull();

    ($this->debitNote)($bill, 300000);
    expect(($this->warning)($asset['id']))->toMatchArray(['code' => 'source_credited', 'document_type' => 'bill', 'document_id' => $bill['id']]);
});

it('lists the flagged asset on the depreciation preview and the drafted run, and still lets the run post', function (): void {
    [$bill, $asset] = ($this->billAsset)();
    $clean = $this->capitalisedAsset($this->category['id']);
    $this->asPerson($this->owner, signing: true)->postJson($this->api("bills/{$bill['id']}/reverse"), [], $this->key())->assertOk();

    $preview = $this->asPerson($this->accountant)->getJson($this->api('depreciation-runs/preview?period_id='.$this->periodId('2026-03-15')))->assertOk()->json('data');
    $byAsset = collect($preview['lines'])->keyBy('asset_id');
    expect($byAsset[$asset['id']]['source_warning']['code'])->toBe('source_reversed')->and($byAsset[$clean['id']]['source_warning'])->toBeNull()
        ->and($preview['blockers'])->toBe([]);

    $run = $this->draftRun('2026-03-15');
    $lines = collect($run['lines'])->keyBy('asset_id');
    expect($lines[$asset['id']]['source_warning']['code'])->toBe('source_reversed')->and($lines[$clean['id']]['source_warning'])->toBeNull();
    $posted = $this->asPerson($this->accountant, signing: true)->postJson($this->api("depreciation-runs/{$run['id']}/submit"), [], $this->key())->assertOk()->json('data');
    expect($posted['status'])->toBe('posted');
});

it('warns on the close checklist without blocking, naming the asset and the document', function (): void {
    [$bill, $asset] = ($this->billAsset)();
    $period = $this->periodId('2026-03-15');
    $check = fn (): array => collect($this->asPerson($this->owner)->getJson($this->api("fiscal-periods/{$period}/close-checks"))->assertOk()->json('data'))->keyBy('code')['ASSET_SOURCE'];

    expect($check()['passed'])->toBeTrue()->and($check()['detail']['warning'])->toBeFalse();

    $this->asPerson($this->owner, signing: true)->postJson($this->api("bills/{$bill['id']}/reverse"), [], $this->key())->assertOk();
    $c = $check();
    expect($c['passed'])->toBeTrue()->and($c['detail']['warning'])->toBeTrue()->and($c['detail']['assets'])->toHaveCount(1)
        ->and($c['detail']['assets'][0])->toMatchArray(['id' => $asset['id'], 'number' => $asset['number'], 'code' => 'source_reversed', 'document_number' => $bill['number']])
        ->and($c['message'])->toContain('1 asset');
});

it('stops flagging an asset once it is disposed of', function (): void {
    [$bill, $asset] = ($this->billAsset)();
    $this->asPerson($this->owner, signing: true)->postJson($this->api("bills/{$bill['id']}/reverse"), [], $this->key())->assertOk();
    $this->postedRun('2026-03-15');
    $tx = $this->asPerson($this->accountant)->postJson($this->api('asset-transactions'), [
        'kind' => 'disposal', 'tx_date' => '2026-04-15', 'asset_id' => $asset['id'], 'proceeds' => 0, 'reason' => 'Source bill reversed; written off',
    ], $this->key())->assertCreated()->json('data');
    $this->asPerson($this->accountant, signing: true)->postJson($this->api("asset-transactions/{$tx['id']}/submit"), [], $this->key())->assertOk();

    expect(($this->warning)($asset['id']))->toBeNull();
});
