<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/* Plan 3c.21b: the writer for the `bs:current` / `bs:non_current` tag, and chart account 1811 classed non-current. */

beforeEach(function (): void {
    $this->setUpWorld();
    $this->beta = $this->secondCompany();
    $this->owner = $this->person('Owen Owner', ['Owner'], $this->beta->id);
    $this->viewer = $this->person('Vic Viewer', ['Viewer'], $this->beta->id);
    $this->asPerson($this->owner)->postJson($this->api('apply-chart-template', $this->beta->id), ['template_code' => 'comprehensive'], $this->key())->assertCreated();
    $this->idOf = fn (string $code) => $this->inTenant(fn () => DB::table('accounts')->where('company_id', $this->beta->id)->where('code', $code)->value('id'));
});

it('sets, shows and clears the balance sheet class, audited, keeping other tags, and refuses what does not apply', function (): void {
    $id = ($this->idOf)('13010000');
    $this->inTenant(fn () => DB::table('accounts')->where('id', $id)->update(['reporting_tags' => json_encode(['cf:operating'])]));
    $path = $this->api("accounts/{$id}/balance-sheet-class", $this->beta->id);

    $this->asPerson($this->owner)->putJson($path, ['class' => 'non_current', 'version' => 0], $this->key())
        ->assertOk()->assertJsonPath('data.balance_sheet_class', 'non_current')->assertJsonPath('data.version', 1);
    expect(json_decode((string) $this->inTenant(fn () => DB::table('accounts')->where('id', $id)->value('reporting_tags')), true))->toBe(['cf:operating', 'bs:non_current']);

    // Same answer again: nothing changes, no new version.
    $this->asPerson($this->owner)->putJson($path, ['class' => 'non_current', 'version' => 1], $this->key())->assertOk()->assertJsonPath('data.version', 1);
    $this->asPerson($this->owner)->putJson($path, ['class' => 'current', 'version' => 1], $this->key())->assertOk()->assertJsonPath('data.balance_sheet_class', 'current');
    $this->asPerson($this->owner)->putJson($path, ['class' => null, 'version' => 2], $this->key())->assertOk()->assertJsonPath('data.balance_sheet_class', null);
    expect(json_decode((string) $this->inTenant(fn () => DB::table('accounts')->where('id', $id)->value('reporting_tags')), true))->toBe(['cf:operating']);
    expect($this->inTenant(fn () => DB::table('audit_events')->where('action', 'account.balance_sheet_class_set')->count()))->toBe(3);

    // A stale version, a viewer, a bad value, and an account that is not an asset or liability.
    $this->asPerson($this->owner)->putJson($path, ['class' => 'current', 'version' => 0], $this->key())->assertStatus(409);
    $this->asPerson($this->viewer)->putJson($path, ['class' => 'current', 'version' => 3], $this->key())->assertForbidden();
    $this->asPerson($this->owner)->putJson($path, ['class' => 'sideways', 'version' => 3], $this->key())->assertStatus(422);
    $equity = ($this->idOf)('32010000');
    $this->asPerson($this->owner)->putJson($this->api("accounts/{$equity}/balance-sheet-class", $this->beta->id), ['class' => 'current', 'version' => 0], $this->key())
        ->assertStatus(422)->assertJsonPath('violations.0.code', 'balance_sheet_class_not_applicable');
});

it('refuses a class on deferred tax, which is always non-current', function (): void {
    $dta = $this->inTenant(fn () => DB::table('accounts')->where('company_id', $this->beta->id)->whereIn('ifrs_element', ['DeferredTaxAssets', 'DeferredTaxLiabilities'])->value('id'));
    expect($dta)->not->toBeNull();
    $this->asPerson($this->owner)->putJson($this->api("accounts/{$dta}/balance-sheet-class", $this->beta->id), ['class' => 'current', 'version' => 0], $this->key())
        ->assertStatus(422)->assertJsonPath('violations.0.code', 'balance_sheet_class_fixed');
});

it('presents the allowance for impairment of investments (1811) with the non-current assets', function (): void {
    $id = ($this->idOf)('18110000');
    $r = $this->asPerson($this->owner)->getJson($this->api("accounts/{$id}", $this->beta->id))->assertOk();
    $r->assertJsonPath('data.balance_sheet_default', 'non_current')->assertJsonPath('data.balance_sheet_class', null);
});
