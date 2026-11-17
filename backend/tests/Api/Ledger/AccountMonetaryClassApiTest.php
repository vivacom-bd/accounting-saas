<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/* Plan 3c.29c: the writer for the `fx:monetary` / `fx:non_monetary` tag, so a long-term or intragroup loan is revalued (IAS 21.16, .23). */

beforeEach(function (): void {
    $this->setUpWorld();
    $this->beta = $this->secondCompany();
    $this->owner = $this->person('Owen Owner', ['Owner'], $this->beta->id);
    $this->viewer = $this->person('Vic Viewer', ['Viewer'], $this->beta->id);
    $this->asPerson($this->owner)->postJson($this->api('apply-chart-template', $this->beta->id), ['template_code' => 'comprehensive'], $this->key())->assertCreated();
    $this->idOf = fn (string $code) => $this->inTenant(fn () => DB::table('accounts')->where('company_id', $this->beta->id)->where('code', $code)->value('id'));
});

it('sets, shows and clears the monetary class, audited, keeping other tags', function (): void {
    $id = ($this->idOf)('18090000');   // long-term deposits and prepayments: non-monetary by its subtype
    $this->inTenant(fn () => DB::table('accounts')->where('id', $id)->update(['reporting_tags' => json_encode(['bs:non_current'])]));
    $path = $this->api("accounts/{$id}/monetary-class", $this->beta->id);

    $this->asPerson($this->owner)->getJson($this->api("accounts/{$id}", $this->beta->id))->assertOk()
        ->assertJsonPath('data.monetary_class', null)->assertJsonPath('data.monetary_default', false);
    $this->asPerson($this->owner)->putJson($path, ['class' => 'monetary', 'version' => 0], $this->key())
        ->assertOk()->assertJsonPath('data.monetary_class', 'monetary')->assertJsonPath('data.version', 1);
    expect(json_decode((string) $this->inTenant(fn () => DB::table('accounts')->where('id', $id)->value('reporting_tags')), true))->toBe(['bs:non_current', 'fx:monetary']);

    $this->asPerson($this->owner)->putJson($path, ['class' => 'monetary', 'version' => 1], $this->key())->assertOk()->assertJsonPath('data.version', 1);
    $this->asPerson($this->owner)->putJson($path, ['class' => 'non_monetary', 'version' => 1], $this->key())->assertOk()->assertJsonPath('data.monetary_class', 'non_monetary');
    $this->asPerson($this->owner)->putJson($path, ['class' => null, 'version' => 2], $this->key())->assertOk()->assertJsonPath('data.monetary_class', null);
    expect(json_decode((string) $this->inTenant(fn () => DB::table('accounts')->where('id', $id)->value('reporting_tags')), true))->toBe(['bs:non_current'])
        ->and($this->inTenant(fn () => DB::table('audit_events')->where('action', 'account.monetary_class_set')->count()))->toBe(3);

    $this->asPerson($this->owner)->putJson($path, ['class' => 'monetary', 'version' => 0], $this->key())->assertStatus(409);
    $this->asPerson($this->viewer)->putJson($path, ['class' => 'monetary', 'version' => 3], $this->key())->assertForbidden();
    $this->asPerson($this->owner)->putJson($path, ['class' => 'cash', 'version' => 3], $this->key())->assertStatus(422);
});

it('shows a long-term loan receivable as monetary by default', function (): void {
    $this->asPerson($this->owner)->getJson($this->api('accounts/'.($this->idOf)('18080000'), $this->beta->id))->assertOk()
        ->assertJsonPath('data.monetary_default', true)->assertJsonPath('data.monetary_class', null);
});

it('never makes PPE, equity or profit and loss monetary, and leaves control accounts monetary', function (string $code, string $class): void {
    $id = ($this->idOf)($code);
    $this->asPerson($this->owner)->putJson($this->api("accounts/{$id}/monetary-class", $this->beta->id), ['class' => $class, 'version' => 0], $this->key())
        ->assertStatus(422)->assertJsonPath('violations.0.code', 'monetary_class_not_applicable');
})->with([
    'buildings' => ['16020000', 'monetary'],
    'share capital' => ['32010000', 'monetary'],
    'revenue' => ['41010000', 'non_monetary'],
    'trade receivables' => ['12010000', 'non_monetary'],
]);
