<?php

declare(strict_types=1);

use App\Domain\EInvoicing\EInvoiceFailure;
use App\Domain\EInvoicing\EInvoiceRetryPolicy;
use App\Models\Organisation\Company;
use App\Services\Documents\DocumentData;
use App\Services\EInvoicing\EInvoiceAdapters;
use App\Support\Outbox\EInvoiceOutboxHandler;
use App\Support\Outbox\OutboxMessage;
use App\Support\Testing\FakeEInvoiceAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerChecks;
use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;

uses(MakesApiCalls::class, TradeApi::class);

/*
 * E-invoicing (plan 3c.27): a country-neutral adapter interface and a fake adapter. Posting never depends on an authority: the document
 * posts first, and submission is queued off the outbox. Off for every company until turned on with a configured adapter. There is no
 * real adapter and no eTIMS class; nothing here says a real authority works.
 */

beforeEach(function (): void {
    $this->setUpTrade();
    $this->boss = $this->person('Fay Finance', ['Finance Manager']);
    $this->fake = new FakeEInvoiceAdapter;
    $this->app->instance(FakeEInvoiceAdapter::class, $this->fake);
    config(['einvoicing.adapters' => ['fake' => FakeEInvoiceAdapter::class]]);
    $this->turnOn = fn () => $this->asPerson($this->boss)->putJson($this->api('einvoicing'), ['enabled' => true, 'adapter' => 'fake', 'tax_id' => 'P051234567X', 'branch' => '00'], $this->key());
    $this->subs = fn () => $this->inTenant(fn () => DB::table('einvoice_submissions')->orderBy('created_at')->get()->all());
    $this->status = fn (string $id) => $this->asPerson($this->owner)->getJson($this->api("invoices/{$id}/einvoice"))->assertOk()->json('data');
});

it('is off for every company and sends nothing: the document is simply not_required', function (): void {
    $inv = $this->sale(10000, '2026-03-10');
    expect(($this->status)($inv['id'])['status'])->toBe('not_required')->and(($this->subs)())->toBe([])->and($this->fake->submitted)->toBe([]);
    $this->asPerson($this->owner)->getJson($this->api('einvoicing'))->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.adapters.0.key', 'fake');
});

it('turns on only with a configured, selectable adapter and the seller tax id, and the fake is never selectable in production', function (): void {
    config(['einvoicing.adapters' => []]);
    $this->asPerson($this->boss)->putJson($this->api('einvoicing'), ['enabled' => true, 'adapter' => 'fake', 'tax_id' => 'P0'], $this->key())
        ->assertStatus(422)->assertJsonPath('violations.0.code', 'einvoice_adapter_unavailable');
    config(['einvoicing.adapters' => ['fake' => FakeEInvoiceAdapter::class]]);
    $this->asPerson($this->boss)->putJson($this->api('einvoicing'), ['enabled' => true, 'adapter' => 'fake'], $this->key())
        ->assertStatus(422)->assertJsonPath('violations.0.code', 'einvoice_seller_tax_id_required');
    $this->asPerson($this->assistant)->putJson($this->api('einvoicing'), ['enabled' => true, 'adapter' => 'fake', 'tax_id' => 'P0'], $this->key())->assertForbidden();
    ($this->turnOn)()->assertOk()->assertJsonPath('data.enabled', true);

    // The same configuration in production offers nothing: a test-only adapter is refused whatever the config says.
    $this->app['env'] = 'production';
    expect(app(EInvoiceAdapters::class)->selectable())->toBe([]);
    $this->app['env'] = 'testing';
    expect(array_keys(app(EInvoiceAdapters::class)->selectable()))->toBe(['fake']);
    expect(config('einvoicing.adapters'))->not->toHaveKey('etims');
});

it('posts first and submits after: the authority number is kept apart from our number, and the PDF shows it only when accepted', function (): void {
    ($this->turnOn)()->assertOk();
    $inv = $this->sale(10000, '2026-03-10');
    $s = ($this->status)($inv['id']);
    expect($s['status'])->toBe('accepted')->and($s['authority_number'])->toBe('FAKE-000001')->and($inv['number'])->not->toBe('FAKE-000001')->and($inv['number'])->not->toBeNull();
    $row = ($this->subs)()[0];
    expect($row->idempotency_key)->toBe($inv['id'].':'.$inv['version'])->and($row->document_type)->toBe('invoice')->and($row->qr_payload)->not->toBeNull()->and($row->device_id)->toBe('fake-device');
    $sent = $this->fake->submitted[0];
    expect($sent->documentType)->toBe('invoice')->and($sent->seller)->toBe(['tax_id' => 'P051234567X', 'branch' => '00'])->and($sent->totals)->toBe(['net' => 10000, 'tax' => 1000, 'gross' => 11000])
        ->and($sent->lines[0]['tax_class'])->toBe('VAT10')->and($sent->lines[0]['tax_amount'])->toBe(1000)->and($sent->currency)->toBe('USD')->and($sent->original)->toBeNull();

    $company = $this->inTenant(fn () => Company::query()->findOrFail($this->f->companyId));
    $printed = $this->inTenant(fn () => app(DocumentData::class)->build('invoice', $company, $inv['id']));
    expect($printed['data']['einvoice']['number'])->toBe('FAKE-000001')->and($printed['data']['einvoice']['qr'])->toStartWith('data:image/svg+xml;base64,');
    $html = view($printed['view'], $printed['data'])->render();
    expect($html)->toContain('FAKE-000001')->toContain('data:image/svg+xml');

    // A pending (not yet accepted) document prints neither.
    $this->inTenant(fn () => DB::table('einvoice_submissions')->update(['status' => 'pending']));
    $pending = $this->inTenant(fn () => app(DocumentData::class)->build('invoice', $company, $inv['id']));
    expect($pending['data']['einvoice'])->toBeNull()->and(view($pending['view'], $pending['data'])->render())->not->toContain('FAKE-000001');
});

it('still posts, numbers and balances when the authority is down, then sends on a back-off and when retried', function (): void {
    ($this->turnOn)()->assertOk();
    $this->fake->down = true;
    $inv = $this->sale(10000, '2026-03-10');
    expect($inv['status'])->toBe('posted')->and($inv['number'])->not->toBeNull();
    $this->inTenant(fn () => expect(LedgerChecks::problems())->toBe([]));
    $s = ($this->status)($inv['id']);
    expect($s['status'])->toBe('failed_retryable')->and($s['attempts'])->toBe(1)->and($s['last_error_code'])->toBe('authority_down')->and($s['can_retry'])->toBeTrue()->and($s['dead_lettered'])->toBeFalse();
    $due = $this->inTenant(fn () => DB::table('einvoice_submissions')->value('next_attempt_at'));
    expect(strtotime((string) $due) - $this->clock->now()->getTimestamp())->toBe(60);

    // Not due yet: the sweep leaves it. After the wait, with the authority back, it goes through.
    $this->artisan('einvoice:sweep')->assertSuccessful();
    expect($this->fake->submitted)->toHaveCount(1);
    $this->fake->down = false;
    $this->clock->advance('+61 seconds');
    $this->artisan('einvoice:sweep')->assertSuccessful();
    expect(($this->status)($inv['id'])['status'])->toBe('accepted')->and($this->fake->submitted)->toHaveCount(2);
});

it('queries before resending after a timeout, so a document the authority holds is never sent twice', function (): void {
    ($this->turnOn)()->assertOk();
    $this->fake->willTimeoutAfterAccepting();
    $inv = $this->sale(10000, '2026-03-10');
    expect(($this->status)($inv['id'])['status'])->toBe('unknown')->and($this->fake->submitted)->toHaveCount(1);
    $this->clock->advance('+2 minutes');
    $this->artisan('einvoice:sweep')->assertSuccessful();
    expect(($this->status)($inv['id'])['status'])->toBe('accepted')->and($this->fake->submitted)->toHaveCount(1)->and($this->fake->queried)->toBe([$inv['id'].':'.$inv['version']])
        ->and(($this->status)($inv['id'])['authority_number'])->toBe('FAKE-000001');

    // A timeout where the authority has nothing: the query finds nothing and the document is sent again.
    $this->fake->willFail(EInvoiceFailure::timeout());
    $b = $this->sale(2000, '2026-03-11');
    expect(($this->status)($b['id'])['status'])->toBe('unknown');
    $this->clock->advance('+2 minutes');
    $this->artisan('einvoice:sweep')->assertSuccessful();
    expect(($this->status)($b['id'])['status'])->toBe('accepted');
});

it('treats a rejection as final: never resent, not retryable', function (): void {
    ($this->turnOn)()->assertOk();
    $this->fake->willFail(EInvoiceFailure::rejected('buyer_pin_invalid', 'The buyer PIN is not valid.', [], ['code' => 'E42']));
    $inv = $this->sale(10000, '2026-03-10');
    $s = ($this->status)($inv['id']);
    expect($s['status'])->toBe('rejected')->and($s['last_error_code'])->toBe('buyer_pin_invalid')->and($s['can_retry'])->toBeFalse();
    $this->clock->advance('+2 days');
    $this->artisan('einvoice:sweep')->assertSuccessful();
    expect($this->fake->submitted)->toHaveCount(1);
    $this->asPerson($this->boss)->postJson($this->api("invoices/{$inv['id']}/einvoice/retry"), [], $this->key())->assertStatus(422)->assertJsonPath('violations.0.code', 'einvoice_not_retryable');
    $raw = $this->inTenant(fn () => DB::table('einvoice_submissions')->value('raw_response'));
    expect(json_decode((string) $raw, true))->toBe(['code' => 'E42']);
});

it('dead-letters after the maximum attempts, and a person can ask for a fresh round', function (): void {
    ($this->turnOn)()->assertOk();
    $this->fake->down = true;
    $inv = $this->sale(10000, '2026-03-10');
    for ($i = 1; $i < EInvoiceRetryPolicy::MAX_ATTEMPTS; $i++) {
        $this->clock->advance('+2 days');
        $this->artisan('einvoice:sweep')->assertSuccessful();
    }
    $s = ($this->status)($inv['id']);
    expect($s['attempts'])->toBe(EInvoiceRetryPolicy::MAX_ATTEMPTS)->and($s['dead_lettered'])->toBeTrue()->and($s['next_attempt_at'])->toBeNull()->and($s['can_retry'])->toBeTrue();
    $this->clock->advance('+30 days');
    $this->artisan('einvoice:sweep')->assertSuccessful();
    expect($this->fake->submitted)->toHaveCount(EInvoiceRetryPolicy::MAX_ATTEMPTS);

    $this->fake->down = false;
    $this->asPerson($this->accountant)->postJson($this->api("invoices/{$inv['id']}/einvoice/retry"), [], $this->key())->assertForbidden();
    $this->asPerson($this->boss)->postJson($this->api("invoices/{$inv['id']}/einvoice/retry"), [], $this->key())->assertOk()->assertJsonPath('data.status', 'accepted');
    expect(($this->status)($inv['id'])['dead_lettered'])->toBeFalse();
});

it('backs off 1 minute, 5 minutes, 15 minutes, 1 hour, 4 hours, 12 hours, 24 hours, then gives up', function (): void {
    expect(array_map(fn (int $n): ?int => EInvoiceRetryPolicy::delayAfter($n), range(1, 8)))->toBe([60, 300, 900, 3600, 14400, 43200, 86400, null]);
});

it('sends a credit note after the invoice it credits is accepted, names the invoice authority number, and a full credit voids it', function (): void {
    ($this->turnOn)()->assertOk();
    $this->fake->down = true;
    $inv = $this->sale(10000, '2026-03-10');                                  // down: failed_retryable
    $note = $this->postedDoc('invoices', $this->t->body($this->t->customer, [[10000, '1', 'VAT10']], '2026-03-12', ['kind' => 'credit_note', 'related_invoice_id' => $inv['id']]));
    // The credit note waits for the invoice, without using an attempt, so it can name the invoice's authority number.
    expect(($this->status)($note['id'])['status'])->toBe('pending')->and(($this->status)($note['id'])['attempts'])->toBe(0)->and(($this->status)($note['id'])['last_error_code'])->toBe('waiting_for_original');

    $this->fake->down = false;
    $this->clock->advance('+2 minutes');
    $this->artisan('einvoice:sweep')->assertSuccessful();
    // The invoice goes first; the credit note is tried once its wait is over and then carries the invoice's number.
    $this->clock->advance('+6 minutes');
    $this->artisan('einvoice:sweep')->assertSuccessful();
    $sent = collect($this->fake->submitted)->firstWhere('documentType', 'credit_note');
    expect(($this->status)($inv['id'])['status'])->toBe('voided_by_credit')->and(($this->status)($note['id'])['status'])->toBe('accepted')
        ->and($sent->original['number'])->toBe($inv['number'])->and($sent->original['authority_number'])->toStartWith('FAKE-');
});

it('keeps an invoice accepted when a credit note covers only part of it', function (): void {
    ($this->turnOn)()->assertOk();
    $inv = $this->sale(10000, '2026-03-10');
    $this->postedDoc('invoices', $this->t->body($this->t->customer, [[3000, '1', 'VAT10']], '2026-03-12', ['kind' => 'credit_note', 'related_invoice_id' => $inv['id']]));
    expect(($this->status)($inv['id'])['status'])->toBe('accepted');
});

it('queues one submission per document version even if the outbox message is delivered twice', function (): void {
    ($this->turnOn)()->assertOk();
    $inv = $this->sale(10000, '2026-03-10');
    $payload = ['document_id' => $inv['id'], 'company_id' => $this->f->companyId, 'kind' => 'sales_invoice', 'number' => $inv['number'], 'journal_id' => $inv['journal_id'], 'version' => $inv['version']];
    $msg = new OutboxMessage((string) Str::uuid7(), $this->f->tenantId, 'invoice.posted', $payload, '2026-03-15T09:30:00Z', 1);
    $this->inTenant(function () use ($msg): void {
        app(EInvoiceOutboxHandler::class)->handle($msg);
        app(EInvoiceOutboxHandler::class)->handle($msg);
    });
    expect(($this->subs)())->toHaveCount(1)->and($this->fake->submitted)->toHaveCount(1);
});

it('sends nothing for a purchase bill', function (): void {
    ($this->turnOn)()->assertOk();
    $this->postedDoc('bills', $this->t->body($this->t->supplier, [[5000, '1', 'VAT10', '0', '5000']], '2026-03-13'));
    expect(($this->subs)())->toBe([]);
});
