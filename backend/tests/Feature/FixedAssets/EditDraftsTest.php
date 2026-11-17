<?php

declare(strict_types=1);

/*
 * Plan 3c.15c: a draft depreciation run or asset transaction is edited in place, with the version (409 when stale) and an
 * audit event. Once submitted or posted it is immutable; the way back is return, void or reverse.
 */

beforeEach(function (): void {
    $this->setUpAssets();
    $this->category = $this->assetCategory();
    $this->asset = $this->capitalisedAsset($this->category['id']);
    $this->put = fn (string $path, array $body) => $this->asPerson($this->accountant)->putJson($this->api($path), $body, $this->key());
});

describe('depreciation run', function (): void {
    it('moves a draft to another period and re-plans the figures: April catches March up, 2,000.00', function (): void {
        $run = $this->draftRun('2026-03-15');
        expect($run['total']['amount'])->toBe(100000)->and($run['version'])->toBe(0);

        $edited = ($this->put)("depreciation-runs/{$run['id']}", ['period_id' => $this->periodId('2026-04-15'), 'memo' => 'April', 'version' => 0])->assertOk()->json('data');

        expect($edited['id'])->toBe($run['id'])->and($edited['as_of'])->toBe('2026-04-30')->and($edited['total']['amount'])->toBe(200000)
            ->and($edited['memo'])->toBe('April')->and($edited['version'])->toBe(1)->and($edited['lines'])->toHaveCount(1)
            ->and($edited['lines'][0]['months'])->toBe(2)->and($edited['lines'][0]['catch_up_months'])->toBe(1)
            ->and(adb('depreciation_runs')->count())->toBe(1)->and(adb('depreciation_run_lines')->count())->toBe(1);
        $event = adb('audit_events')->where('action', 'depreciation_run.updated')->first();
        expect($event)->not->toBeNull();

        $posted = $this->asPerson($this->accountant, signing: true)->postJson($this->api("depreciation-runs/{$run['id']}/submit"), ['version' => 1], $this->key())->assertOk()->json('data');
        expect($posted['status'])->toBe('posted')->and($posted['total']['amount'])->toBe(200000);
    });

    it('keeps the same period without clashing with itself, and refuses a period another run holds', function (): void {
        $march = $this->draftRun('2026-03-15');
        ($this->put)("depreciation-runs/{$march['id']}", ['period_id' => $march['fiscal_period_id'], 'memo' => 'Note', 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);

        $this->asPerson($this->accountant)->postJson($this->api('depreciation-runs'), ['period_id' => $this->periodId('2026-04-15')], $this->key())
            ->assertStatus(422)->assertJsonPath('code', 'earlier_run_pending');
        $this->asPerson($this->accountant, signing: true)->postJson($this->api("depreciation-runs/{$march['id']}/submit"), [], $this->key())->assertOk();
        $april = $this->draftRun('2026-04-15');
        ($this->put)("depreciation-runs/{$april['id']}", ['period_id' => $march['fiscal_period_id'], 'version' => 0])
            ->assertStatus(422)->assertJsonPath('code', 'depreciation_run_exists');
    });

    it('answers 409 version_conflict on a stale version and changes nothing', function (): void {
        $run = $this->draftRun('2026-03-15');
        ($this->put)("depreciation-runs/{$run['id']}", ['period_id' => $run['fiscal_period_id'], 'memo' => 'First', 'version' => 0])->assertOk();

        ($this->put)("depreciation-runs/{$run['id']}", ['period_id' => $run['fiscal_period_id'], 'memo' => 'Second', 'version' => 0])
            ->assertStatus(409)->assertJson(['code' => 'version_conflict', 'server_version' => 1]);
        expect(adb('depreciation_runs')->value('memo'))->toBe('First');
    });

    it('does not edit a run once it is posted or waiting for approval', function (): void {
        $posted = $this->postedRun('2026-03-15');
        ($this->put)("depreciation-runs/{$posted['id']}", ['period_id' => $posted['fiscal_period_id'], 'memo' => 'x', 'version' => $posted['version']])->assertForbidden();
        expect(adb('depreciation_runs')->value('memo'))->not->toBe('x');
    });

    it('needs the right permission and a period that is open', function (): void {
        $run = $this->draftRun('2026-03-15');
        $this->asPerson($this->approver)->putJson($this->api("depreciation-runs/{$run['id']}"), ['period_id' => $run['fiscal_period_id'], 'version' => 0], $this->key())->assertForbidden();
        $this->asPerson($this->assistant)->putJson($this->api("depreciation-runs/{$run['id']}"), ['period_id' => $run['fiscal_period_id'], 'version' => 0], $this->key())->assertForbidden();
        ($this->put)("depreciation-runs/{$run['id']}", ['period_id' => $run['fiscal_period_id']])->assertStatus(422);
    });
});

describe('asset transaction', function (): void {
    beforeEach(function (): void {
        $this->postedRun('2026-03-15');
        $this->postedRun('2026-04-15');
        $this->tx = $this->asPerson($this->accountant)->postJson($this->api('asset-transactions'), [
            'kind' => 'impairment', 'tx_date' => '2026-05-15', 'asset_id' => $this->asset['id'], 'amount' => 1500000, 'reason' => 'Fire damage',
        ], $this->key())->assertCreated()->json('data');
    });

    it('edits the amount, date and reason of a draft and re-plans it', function (): void {
        $edited = ($this->put)("asset-transactions/{$this->tx['id']}", [
            'kind' => 'impairment', 'tx_date' => '2026-05-20', 'asset_id' => $this->asset['id'], 'amount' => 2000000, 'reason' => 'Flood damage', 'version' => 0,
        ])->assertOk()->json('data');

        expect($edited['id'])->toBe($this->tx['id'])->and($edited['version'])->toBe(1)->and($edited['tx_date'])->toBe('2026-05-20')
            ->and($edited['reason'])->toBe('Flood damage')->and($edited['amount']['amount'])->toBe(2000000)->and($edited['lines'])->toHaveCount(1)
            ->and(adb('asset_transactions')->count())->toBe(1)->and(adb('asset_transaction_lines')->count())->toBe(1)
            ->and((int) adb('asset_transaction_lines')->value('input_amount'))->toBe(2000000);
        expect(adb('audit_events')->where('action', 'asset_transaction.updated')->count())->toBe(1);

        $posted = $this->asPerson($this->accountant, signing: true)->postJson($this->api("asset-transactions/{$this->tx['id']}/submit"), ['version' => 1], $this->key())->assertOk()->json('data');
        expect($posted['status'])->toBe('posted');
        $journal = $this->journalLines($posted['journal_id']);
        expect($journal[0])->toBe(['account' => '8600', 'debit' => 2000000, 'credit' => 0]);
    });

    it('does not change the kind of a draft, and still refuses what a new draft would refuse', function (): void {
        ($this->put)("asset-transactions/{$this->tx['id']}", [
            'kind' => 'disposal', 'tx_date' => '2026-05-15', 'asset_id' => $this->asset['id'], 'proceeds' => 0, 'version' => 0,
        ])->assertStatus(422)->assertJsonPath('code', 'asset_transaction_kind_locked');
        ($this->put)("asset-transactions/{$this->tx['id']}", [
            'kind' => 'impairment', 'tx_date' => '2026-05-15', 'asset_id' => $this->asset['id'], 'amount' => 999999999, 'reason' => 'Too much', 'version' => 0,
        ])->assertStatus(422);
        expect((int) adb('asset_transactions')->value('amount'))->toBe(1500000)->and((int) adb('asset_transactions')->value('lock_version'))->toBe(0);
    });

    it('answers 409 on a stale version and refuses a posted one', function (): void {
        $body = ['kind' => 'impairment', 'tx_date' => '2026-05-15', 'asset_id' => $this->asset['id'], 'amount' => 1000000, 'reason' => 'Fire damage'];
        ($this->put)("asset-transactions/{$this->tx['id']}", [...$body, 'version' => 0])->assertOk();
        ($this->put)("asset-transactions/{$this->tx['id']}", [...$body, 'version' => 0])->assertStatus(409)->assertJson(['code' => 'version_conflict', 'server_version' => 1]);

        $this->asPerson($this->accountant, signing: true)->postJson($this->api("asset-transactions/{$this->tx['id']}/submit"), [], $this->key())->assertOk();
        ($this->put)("asset-transactions/{$this->tx['id']}", [...$body, 'version' => 2])->assertForbidden();
    });

    it('needs the permission of its kind', function (): void {
        $this->asPerson($this->assistant)->putJson($this->api("asset-transactions/{$this->tx['id']}"), [
            'kind' => 'impairment', 'tx_date' => '2026-05-15', 'asset_id' => $this->asset['id'], 'amount' => 1000000, 'reason' => 'x', 'version' => 0,
        ], $this->key())->assertForbidden();
    });
});
