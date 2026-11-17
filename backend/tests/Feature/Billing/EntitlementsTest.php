<?php

declare(strict_types=1);

use App\Actions\Ledger\Inputs\RecordJournalInput;
use App\Actions\Ledger\RecordJournal;
use App\Actions\Organisation\AcceptInvitation;
use App\Actions\Organisation\CreateCompany;
use App\Actions\Organisation\Inputs\AcceptInvitationInput;
use App\Actions\Organisation\Inputs\ApiClientInput;
use App\Actions\Organisation\Inputs\CreateCompanyInput;
use App\Actions\Organisation\Inputs\InviteMemberInput;
use App\Actions\Organisation\InviteMember;
use App\Actions\Organisation\ManageApiClient;
use App\Actions\Organisation\SeedCompanyRoles;
use App\Domain\Ledger\DraftLine;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\JournalType;
use App\Domain\Ledger\PostingRejected;
use App\Domain\Shared\DomainRuleViolation;
use App\Services\Billing\Entitlements;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\UsageOutboxHandler;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LedgerFixture;

/* Entitlements, limits and usage metering (plan Task 10, G8, T7). */

function billingTenant(array $limits = [], string $slug = 'acme'): LedgerFixture
{
    $f = LedgerFixture::create(slug: $slug);
    app(SeedCompanyRoles::class)->handle($f->actor, $f->companyId);
    billPutOnPlan($f, $limits);

    return $f;
}

/** Puts the tenant on a fresh plan with the given limits (the rest unlimited), as provisioning does. */
function billPutOnPlan(LedgerFixture $f, array $limits = []): void
{
    $platform = DB::connection('pgsql_platform');
    $code = 'plan-'.substr(md5(json_encode($limits).$f->tenantId), 0, 8);
    $planId = (string) Str::uuid7();
    $platform->table('plans')->insert(['id' => $planId, 'code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]);
    $keys = ['companies', 'users', 'api_clients', 'journal_lines_per_month', 'storage_mb', 'api_calls_per_day'];
    $all = array_merge(array_fill_keys($keys, null), $limits);
    $versionId = (string) Str::uuid7();
    $platform->table('plan_versions')->insert([
        'id' => $versionId, 'plan_id' => $planId, 'version' => 1, 'prices' => '{}', 'limits' => json_encode($all),
        'entitlements' => json_encode(['multi_currency' => true, 'budgets' => false]), 'effective_from' => '2026-01-01T00:00:00Z',
    ]);
    DB::connection('pgsql_owner')->table('tenants')->where('id', $f->tenantId)->update(['settings' => json_encode(['plan_version_id' => $versionId])]);
}

function billAddCompany(LedgerFixture $f, string $name): void
{
    app(CreateCompany::class)->handle($f->actor, new CreateCompanyInput($name, 'US', 'USD', 1));
}

function billViolation(Closure $call): ?DomainRuleViolation
{
    try {
        $call();
    } catch (DomainRuleViolation $e) {
        return $e;
    }

    return null;
}

function billOverride(LedgerFixture $f, string $key, mixed $value, ?string $expires = null): void
{
    DB::connection('pgsql_platform')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $f->tenantId, 'key' => $key],
        ['value' => json_encode($value), 'reason' => 'test', 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()],
    );
}

function billPostJournal(LedgerFixture $f, string $date = '2026-03-15', int $amount = 1000): void
{
    app(RecordJournal::class)->handle(
        Actor::system($f->tenantId, $f->companyId),
        new RecordJournalInput(new JournalDraft($f->companyId, JournalType::Manual, new DateTimeImmutable($date), [
            new DraftLine($f->account('1000'), debit: $amount), new DraftLine($f->account('4000'), credit: $amount),
        ], 'T', 'test')),
    );
}

/** @return array<string, mixed> the meter for a key */
function billMeter(string $key): array
{
    foreach (app(Entitlements::class)->meters() as $m) {
        if ($m['key'] === $key) {
            return $m;
        }
    }
    throw new RuntimeException("no meter {$key}");
}

it('fourth_company_is_refused_on_a_three_company_plan', function (): void {
    $f = billingTenant(['companies' => 3]);
    billAddCompany($f, 'Beta');
    billAddCompany($f, 'Gamma');

    $e = billViolation(fn () => billAddCompany($f, 'Delta'));

    expect($e?->errorCode)->toBe('plan_limit_reached')
        ->and($e->meta)->toBe(['key' => 'companies', 'limit' => 3, 'current' => 3]);
    expect(DB::table('companies')->count())->toBe(3);
});

it('defaults tenants without a plan to the starter plan, and enforces nothing when there is no catalogue', function (): void {
    $f = LedgerFixture::create();
    expect(app(Entitlements::class)->limit('companies'))->toBeNull(); // no catalogue rows: nothing to enforce

    PlanSeeder::sync(DB::connection('pgsql_platform'));
    expect(app(Entitlements::class)->limit('companies'))->toBe(1)->and(app(Entitlements::class)->limit('users'))->toBe(3);
    expect(billViolation(fn () => billAddCompany($f, 'Second'))?->errorCode)->toBe('plan_limit_reached');
});

it('override_lifts_the_limit_until_it_expires', function (): void {
    $f = billingTenant(['companies' => 1]);
    expect(billViolation(fn () => billAddCompany($f, 'Beta'))?->errorCode)->toBe('plan_limit_reached');

    billOverride($f, 'companies', 2, now()->addDay()->toIso8601String());
    billAddCompany($f, 'Beta');
    expect(DB::table('companies')->count())->toBe(2);
    expect(billViolation(fn () => billAddCompany($f, 'Gamma'))?->errorCode)->toBe('plan_limit_reached');

    billOverride($f, 'companies', null, now()->addDay()->toIso8601String()); // unlimited
    billAddCompany($f, 'Gamma');

    billOverride($f, 'companies', null, now()->subMinute()->toIso8601String()); // expired: the plan's limit applies again
    expect(billViolation(fn () => billAddCompany($f, 'Delta'))?->errorCode)->toBe('plan_limit_reached');
});

it('inviting_beyond_user_limit_counts_open_invitations', function (): void {
    Notification::fake();
    $f = billingTenant(['users' => 3]); // the owner is one
    $invite = fn (string $email) => app(InviteMember::class)->handle($f->actor, new InviteMemberInput($email, 'member', 'Accountant', [$f->companyId]));

    $invite('a@acme.test');
    $invite('b@acme.test');
    $e = billViolation(fn () => $invite('c@acme.test'));
    expect($e?->errorCode)->toBe('plan_limit_reached')->and($e->meta)->toBe(['key' => 'users', 'limit' => 3, 'current' => 3]);

    // Re-inviting an email replaces its open invitation: it does not count twice.
    $invite('b@acme.test');
    expect(DB::table('invitations')->whereNull('revoked_at')->count())->toBe(2);
});

it('accepting_an_invitation_rechecks_the_user_limit', function (): void {
    Notification::fake();
    $f = billingTenant(['users' => 2]);
    app(InviteMember::class)->handle($f->actor, new InviteMemberInput('new@acme.test', 'member', 'Accountant', [$f->companyId]));
    $invitation = DB::table('invitations')->first();
    DB::table('invitations')->where('id', $invitation->id)->update(['token_hash' => hash('sha256', 'tok')]);
    $userId = (string) Str::uuid7();
    DB::table('users')->insert(['id' => $userId, 'name' => 'New', 'email' => 'new@acme.test', 'password' => 'x']);
    $accept = fn () => app(AcceptInvitation::class)->handle(
        new Actor(ActorType::User, $userId, $f->tenantId, requestId: 'test'),
        new AcceptInvitationInput($invitation->id, 'tok'),
    );

    billOverride($f, 'users', 1); // the plan shrank since the invitation went out
    expect(billViolation($accept)?->errorCode)->toBe('plan_limit_reached');

    DB::connection('pgsql_platform')->table('tenant_entitlement_overrides')->where('tenant_id', $f->tenantId)->delete();
    $accept();
    expect(DB::table('tenant_users')->where('user_id', $userId)->exists())->toBeTrue();
});

it('api_client_limit_is_enforced_on_creation', function (): void {
    $f = billingTenant(['api_clients' => 1]);
    $create = fn () => app(ManageApiClient::class)->create($f->actor, new ApiClientInput('Sync', [$f->companyId], ['invoices:read']));

    $create();
    $e = billViolation($create);
    expect($e?->errorCode)->toBe('plan_limit_reached')->and($e->meta)->toBe(['key' => 'api_clients', 'limit' => 1, 'current' => 1]);
    expect(DB::table('api_clients')->count())->toBe(1);
});

it('posting_is_never_blocked_by_journal_line_usage', function (): void {
    $f = billingTenant(['journal_lines_per_month' => 10]);
    DB::select("SELECT sysops.bump_usage('journal_lines', 15)"); // 150 %

    billPostJournal($f);

    $m = billMeter('journal_lines_per_month');
    expect($m['used'])->toBe(17)->and($m['limit'])->toBe(10)->and($m['level'])->toBe('over');
    expect(DB::table('journals')->count())->toBe(1);
});

it('posting_bumps_journal_lines_after_commit', function (): void {
    $f = billingTenant();
    billPostJournal($f);
    expect(billMeter('journal_lines_per_month')['used'])->toBe(2);

    // A posting that is rejected rolls back, and nothing is counted.
    expect(fn () => billPostJournal($f, '2020-01-01'))->toThrow(PostingRejected::class);
    expect(billMeter('journal_lines_per_month')['used'])->toBe(2);
});

it('counts a redelivered outbox message once', function (): void {
    $f = billingTenant();
    billPostJournal($f);
    $journalId = (string) DB::table('journals')->value('id');
    $message = new OutboxMessage((string) Str::uuid7(), $f->tenantId, 'journal.posted', ['journal_id' => $journalId], now()->toIso8601String(), 1);

    app(UsageOutboxHandler::class)->handle($message);
    app(UsageOutboxHandler::class)->handle($message);

    expect(billMeter('journal_lines_per_month')['used'])->toBe(4); // 2 from the real delivery + 2 once for this message
});

it('meters_warn_at_80_percent_and_are_over_at_100', function (): void {
    billingTenant(['journal_lines_per_month' => 10]);
    DB::select("SELECT sysops.bump_usage('journal_lines', 8)");
    expect(billMeter('journal_lines_per_month'))->toMatchArray(['used' => 8, 'level' => 'warn'])
        ->and(billMeter('journal_lines_per_month')['ratio'])->toBe(0.8);
    DB::select("SELECT sysops.bump_usage('journal_lines', 2)");
    expect(billMeter('journal_lines_per_month')['level'])->toBe('over');
    expect(billMeter('storage_mb'))->toMatchArray(['limit' => null, 'level' => 'ok']);
    expect(billMeter('companies')['used'])->toBe(1);
});

it('allows features by the plan, with overrides', function (): void {
    $f = billingTenant();
    $e = app(Entitlements::class);
    expect($e->allows('multi_currency'))->toBeTrue()->and($e->allows('budgets'))->toBeFalse();
    billOverride($f, 'budgets', true);
    expect($e->allows('budgets'))->toBeTrue();
});
