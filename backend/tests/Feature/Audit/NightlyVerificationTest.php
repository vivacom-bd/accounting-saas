<?php

declare(strict_types=1);

use App\Actions\Fiscal\CreateFiscalYear;
use App\Actions\Fiscal\Inputs\CreateFiscalYearInput;
use App\Support\Actor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\LedgerFixture;
use Tests\Support\TamperAudit;

/*
 * Nightly audit verification (13 §14, plan 4.2): `audit:verify-all` queues one verification per live tenant; a broken chain is
 * recorded once, logged, and told to the organisation owners; an intact tenant is not. Scheduled before the nightly seal.
 */

function chainedTenant(string $slug): LedgerFixture
{
    $f = LedgerFixture::create(slug: $slug);
    app(CreateFiscalYear::class)->handle(Actor::system($f->tenantId, $f->companyId), new CreateFiscalYearInput($f->companyId, new DateTimeImmutable('2027-01-01'), 'FY2027'));

    return $f;
}

function inTenant(LedgerFixture $f, Closure $read): mixed
{
    return app(TenantContext::class)->run($f->tenantId, $read);
}

function verifyAll(string ...$options): void
{
    app(TenantContext::class)->clear();
    test()->artisan('audit:verify-all', array_fill_keys($options, true))->assertSuccessful();
}

it('is scheduled every night before the seal, with a full check weekly, and the drift check after it', function (): void {
    $at = fn (string $needle, bool $full = false): ?string => collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, $needle) && str_contains($e->command, '--full') === $full)?->expression;

    expect($at('audit:verify-all'))->toBe('0 1 * * *')->and($at('audit:verify-all', full: true))->toBe('0 3 * * 0')
        ->and($at('audit:seal'))->toBe('30 1 * * *')->and($at('ledger:check-drift'))->toBe('0 2 * * *');
});

it('reports a tampered chain for that tenant only, once, and tells its owners', function (): void {
    $bad = chainedTenant('alpha');
    $good = chainedTenant('beta');
    app(TenantContext::class)->clear();
    TamperAudit::as($bad->tenantId, fn (Connection $db) => $db->update("UPDATE audit_events SET request_id = 'forged' WHERE id = (SELECT min(id) FROM audit_events)"));
    Log::spy();

    verifyAll('--full');

    $finding = inTenant($bad, fn () => DB::table('audit_chain_findings')->sole());
    expect($finding->problem)->toBe('canonical_mismatch')->and((int) $finding->broken_at_id)->toBeGreaterThan(0)->and($finding->full_check)->toBeTrue()
        ->and(inTenant($bad, fn () => DB::table('user_notifications')->where('user_id', $bad->userId)->where('kind', 'audit_chain_broken')->count()))->toBe(1)
        ->and(inTenant($good, fn () => DB::table('audit_chain_findings')->count() + DB::table('user_notifications')->where('kind', 'audit_chain_broken')->count()))->toBe(0);
    Log::shouldHaveReceived('error')->once();

    // Still broken the same way tomorrow: nothing new is recorded or sent.
    verifyAll('--full');
    expect(inTenant($bad, fn () => DB::table('audit_chain_findings')->count()))->toBe(1)
        ->and(inTenant($bad, fn () => DB::table('user_notifications')->where('kind', 'audit_chain_broken')->count()))->toBe(1);
});

it('checks what was written since the last seal every night, and the whole chain on the full run; a clean chain records nothing', function (): void {
    $f = chainedTenant('gamma');
    app(TenantContext::class)->clear();
    $this->artisan('audit:seal')->assertSuccessful();
    verifyAll();
    expect(inTenant($f, fn () => DB::table('audit_chain_findings')->count()))->toBe(0);

    // An event rewritten before the seal is only in reach of the full run; one written after it is caught the same night.
    $sealed = inTenant($f, fn () => (int) DB::table('audit_events')->min('id'));
    TamperAudit::as($f->tenantId, fn (Connection $db) => $db->update("UPDATE audit_events SET request_id = 'forged' WHERE id = {$sealed}"));
    verifyAll();
    expect(inTenant($f, fn () => DB::table('audit_chain_findings')->count()))->toBe(0);
    verifyAll('--full');
    expect(inTenant($f, fn () => DB::table('audit_chain_findings')->pluck('problem')->all()))->toBe(['canonical_mismatch']);

    inTenant($f, fn () => app(CreateFiscalYear::class)->handle(Actor::system($f->tenantId, $f->companyId), new CreateFiscalYearInput($f->companyId, new DateTimeImmutable('2028-01-01'), 'FY2028')));
    $fresh = inTenant($f, fn () => (int) DB::table('audit_events')->max('id'));
    TamperAudit::as($f->tenantId, fn (Connection $db) => $db->update("UPDATE audit_events SET request_id = 'forged' WHERE id = {$fresh}"));
    verifyAll();
    expect(inTenant($f, fn () => DB::table('audit_chain_findings')->orderBy('broken_at_id')->pluck('broken_at_id')->map(fn ($id) => (int) $id)->all()))->toBe([$sealed, $fresh]);
});
