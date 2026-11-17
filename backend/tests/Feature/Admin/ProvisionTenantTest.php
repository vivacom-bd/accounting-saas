<?php

declare(strict_types=1);

use App\Actions\Admin\Plans\CreatePlan;
use App\Actions\Admin\Tenants\Inputs\ProvisionTenantInput;
use App\Actions\Admin\Tenants\ProvisionTenant;
use App\Actions\Organisation\AcceptInvitation;
use App\Actions\Organisation\Inputs\AcceptInvitationInput;
use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Models\Platform\User;
use App\Notifications\InvitationNotification;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Admin\AdminActor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function provActor(): AdminActor
{
    $admin = AdminUser::query()->create([
        'id' => (string) Str::uuid7(), 'email' => 'sales'.Str::lower(Str::random(5)).'@ledgerly.test', 'name' => 'Sam', 'password' => 'x', 'role' => AdminRole::Sales,
    ]);

    return new AdminActor($admin->id, AdminRole::Sales, '127.0.0.1', 'pest', 'req-1');
}

/** @param  array<string, array{monthly: int|null, annual: int|null}>|null  $prices */
function provPlan(string $code = 'starter', int $trialDays = 14, ?array $prices = null): string
{
    $plan = app(CreatePlan::class)(
        provActor(), $code, ucfirst($code), null, 1,
        $prices ?? ['USD' => ['monthly' => 4900, 'annual' => 47000], 'KES' => ['monthly' => 640000, 'annual' => 6140000], 'EUR' => ['monthly' => 4500, 'annual' => 43200]],
        ['companies' => 1, 'users' => 3, 'api_clients' => 1, 'journal_lines_per_month' => 1000, 'storage_mb' => 2048, 'api_calls_per_day' => null],
        ['multi_currency' => false, 'independent_approvals' => false, 'fixed_assets' => false, 'budgets' => false,
            'api_access' => true, 'consolidation' => false, 'audit_chain_export' => true, 'custom_roles' => false],
        $trialDays, null,
    );

    return (string) $plan->versions()->value('id');
}

/** @param  array<string, mixed>  $over */
function provInput(array $over = []): ProvisionTenantInput
{
    return new ProvisionTenantInput(...[
        'tenantId' => (string) Str::uuid7(), 'organisationName' => 'Acme Ltd', 'country' => 'KE', 'ownerEmail' => 'owner@acme.test',
        'ownerName' => 'Olu Owner', 'planCode' => 'starter', 'billing' => 'trial', 'trialDays' => null, 'note' => null, ...$over,
    ]);
}

function provPlatformDb(): Connection
{
    return DB::connection('pgsql_platform');
}

/** The tenant's invitations, read as the app role inside the tenant. */
function tenantInvitations(string $tenantId): mixed
{
    return app(TenantContext::class)->run($tenantId, fn () => DB::connection('pgsql')->table('invitations')->get());
}

it('provisions a trial tenant with an owner invitation', function (): void {
    Notification::fake();
    $versionId = provPlan(trialDays: 14);
    $admin = provActor();
    $in = provInput(['trialDays' => 30, 'note' => 'Referred by partner']);

    $result = app(ProvisionTenant::class)->handle($admin, $in);

    expect($result->created)->toBeTrue();
    $tenant = provPlatformDb()->table('tenants')->where('id', $in->tenantId)->first();
    expect($tenant->lifecycle)->toBe('trial')->and($tenant->country)->toBe('KE')->and($tenant->name)->toBe('Acme Ltd');
    expect(json_decode($tenant->settings, true)['plan_version_id'])->toBe($versionId);
    $days = (new DateTimeImmutable($tenant->trial_ends_at))->diff($this->clock->now())->days;
    expect($days)->toBeBetween(29, 30);

    $invitations = tenantInvitations($in->tenantId);
    expect($invitations)->toHaveCount(1)->and($invitations[0]->role)->toBe('owner')->and($invitations[0]->email)->toBe('owner@acme.test')
        ->and($invitations[0]->invited_by)->toBeNull();
    Notification::assertSentOnDemand(InvitationNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'owner@acme.test');

    $event = provPlatformDb()->table('tenant_lifecycle_events')->where('tenant_id', $in->tenantId)->first();
    expect($event->from_status)->toBeNull()->and($event->to_status)->toBe('trial')->and($event->actor_type)->toBe('admin')->and($event->actor_id)->toBe($admin->id);
    $audit = provPlatformDb()->table('admin_audit_events')->where('action', 'tenant.provisioned')->first();
    expect($audit->tenant_id)->toBe($in->tenantId)->and($audit->reason)->toBe('Referred by partner');
    $notes = provPlatformDb()->table('tenant_notes')->where('tenant_id', $in->tenantId)->get();
    expect($notes)->toHaveCount(1)->and($notes[0]->body)->toBe('Referred by partner')->and($notes[0]->admin_id)->toBe($admin->id)->and((bool) $notes[0]->pinned)->toBeFalse();
    $tenantAudit = app(TenantContext::class)->run($in->tenantId, fn () => DB::connection('pgsql')->table('audit_events')->where('action', 'organisation.provisioned')->first());
    expect($tenantAudit->actor_type)->toBe('admin')->and($tenantAudit->actor_id)->toBe($admin->id);
});

it('provisions an offline active tenant', function (): void {
    Notification::fake();
    provPlan();
    $in = provInput(['billing' => 'offline']);

    app(ProvisionTenant::class)->handle(provActor(), $in);

    $tenant = provPlatformDb()->table('tenants')->where('id', $in->tenantId)->first();
    expect($tenant->lifecycle)->toBe('active')->and($tenant->trial_ends_at)->toBeNull();
    expect(provPlatformDb()->table('tenant_lifecycle_events')->where('tenant_id', $in->tenantId)->value('to_status'))->toBe('active');
});

it('invites an owner email that already has an account without creating a second user', function (): void {
    Notification::fake();
    provPlan();
    (new User)->forceFill(['id' => (string) Str::uuid7(), 'name' => 'Olu', 'email' => 'owner@acme.test', 'password' => 'x'])->save();
    $before = User::query()->count();

    $in = provInput(['ownerEmail' => 'Owner@Acme.test']);
    app(ProvisionTenant::class)->handle(provActor(), $in);

    expect(User::query()->count())->toBe($before);
    expect(tenantInvitations($in->tenantId))->toHaveCount(1);
});

it('creates one tenant when the same request is retried', function (): void {
    Notification::fake();
    provPlan();
    $in = provInput();
    $admin = provActor();

    $first = app(ProvisionTenant::class)->handle($admin, $in);
    $second = app(ProvisionTenant::class)->handle($admin, $in);

    expect($first->created)->toBeTrue()->and($second->created)->toBeFalse()->and($second->id)->toBe($first->id)
        ->and($second->ownerInvitationExpiresAt)->toEqual($first->ownerInvitationExpiresAt);
    expect(provPlatformDb()->table('tenants')->count())->toBe(1);
    expect(provPlatformDb()->table('tenant_lifecycle_events')->count())->toBe(1);
    expect(provPlatformDb()->table('admin_audit_events')->where('action', 'tenant.provisioned')->count())->toBe(1);
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);
});

it('refuses a reused key carrying a different request', function (): void {
    Notification::fake();
    provPlan();
    $admin = provActor();
    $in = provInput();
    app(ProvisionTenant::class)->handle($admin, $in);

    expect(fn () => app(ProvisionTenant::class)->handle($admin, provInput(['tenantId' => $in->tenantId, 'organisationName' => 'Other Co'])))
        ->toThrow(DomainRuleViolation::class);
});

it('gives organisations with the same name distinct slugs', function (): void {
    Notification::fake();
    provPlan();

    app(ProvisionTenant::class)->handle(provActor(), provInput());
    app(ProvisionTenant::class)->handle(provActor(), provInput());

    $slugs = provPlatformDb()->table('tenants')->pluck('slug')->all();
    expect($slugs)->toHaveCount(2)->and(array_unique($slugs))->toHaveCount(2)->and($slugs[0])->toStartWith('acme-ltd');
});

it('lets the owner accept the invitation and become the organisation owner', function (): void {
    Notification::fake();
    provPlan();
    $in = provInput();
    app(ProvisionTenant::class)->handle(provActor(), $in);

    $token = '';
    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n) use (&$token): bool {
        $token = $n->token;

        return true;
    });
    $invitationId = (string) tenantInvitations($in->tenantId)[0]->id;

    $userId = (string) Str::uuid7();
    app(TenantContext::class)->run($in->tenantId, fn () => app(AcceptInvitation::class)->handle(
        new Actor(ActorType::User, $userId, $in->tenantId),
        new AcceptInvitationInput($invitationId, $token, 'Olu Owner', bcrypt('Sup3r-secret-pw')),
    ));

    expect(provPlatformDb()->table('tenant_users')->where('tenant_id', $in->tenantId)->where('user_id', $userId)->value('role'))->toBe('owner');
});

it('rejects an unknown plan and creates nothing', function (): void {
    Notification::fake();
    provPlan();

    expect(fn () => app(ProvisionTenant::class)->handle(provActor(), provInput(['planCode' => 'nope'])))->toThrow(DomainRuleViolation::class);
    expect(provPlatformDb()->table('tenants')->count())->toBe(0);
});

it('starts a trialing subscription for a trial tenant', function (): void {
    Notification::fake();
    $versionId = provPlan();
    $in = provInput();

    app(ProvisionTenant::class)->handle(provActor(), $in);

    $sub = provPlatformDb()->table('subscriptions')->where('tenant_id', $in->tenantId)->get();
    expect($sub)->toHaveCount(1)
        ->and($sub[0]->status)->toBe('trialing')->and($sub[0]->plan_version_id)->toBe($versionId)
        ->and($sub[0]->collection)->toBe('offline')->and($sub[0]->interval)->toBe('month')->and($sub[0]->currency)->toBe('USD');
    expect(provPlatformDb()->table('tenants')->where('id', $in->tenantId)->value('lifecycle'))->toBe('trial');
});

it('starts an active offline subscription in the requested currency and interval', function (): void {
    Notification::fake();
    $versionId = provPlan();
    $in = provInput(['billing' => 'offline', 'currency' => 'KES', 'interval' => 'year']);

    app(ProvisionTenant::class)->handle(provActor(), $in);

    $sub = provPlatformDb()->table('subscriptions')->where('tenant_id', $in->tenantId)->first();
    expect($sub->status)->toBe('active')->and($sub->collection)->toBe('offline')->and($sub->interval)->toBe('year')
        ->and($sub->currency)->toBe('KES')->and($sub->plan_version_id)->toBe($versionId);
    expect(json_decode((string) provPlatformDb()->table('tenants')->where('id', $in->tenantId)->value('settings'), true)['plan_version_id'])->toBe($versionId);
    expect(provPlatformDb()->table('subscription_events')->where('tenant_id', $in->tenantId)->value('kind'))->toBe('new');
});

it('does not start a second subscription when the request is retried', function (): void {
    Notification::fake();
    provPlan();
    $in = provInput(['billing' => 'offline']);
    $admin = provActor();

    app(ProvisionTenant::class)->handle($admin, $in);
    app(ProvisionTenant::class)->handle($admin, $in);

    expect(provPlatformDb()->table('subscriptions')->where('tenant_id', $in->tenantId)->count())->toBe(1);
    expect(provPlatformDb()->table('subscription_events')->where('tenant_id', $in->tenantId)->count())->toBe(1);
});

it('completes the subscription on a retry when the first attempt died before it', function (): void {
    Notification::fake();
    provPlan();
    $in = provInput(['billing' => 'offline']);
    $admin = provActor();
    app(ProvisionTenant::class)->handle($admin, $in);
    provPlatformDb()->table('subscription_events')->where('tenant_id', $in->tenantId)->delete();
    provPlatformDb()->table('subscriptions')->where('tenant_id', $in->tenantId)->delete();

    app(ProvisionTenant::class)->handle($admin, $in);

    expect(provPlatformDb()->table('subscriptions')->where('tenant_id', $in->tenantId)->count())->toBe(1);
});

it('refuses a currency the plan has no price in, and creates nothing', function (): void {
    Notification::fake();
    provPlan(prices: ['USD' => ['monthly' => 4900, 'annual' => 47000]]);
    $in = provInput(['billing' => 'offline', 'currency' => 'EUR']);

    expect(fn () => app(ProvisionTenant::class)->handle(provActor(), $in))->toThrow(DomainRuleViolation::class);
    expect(provPlatformDb()->table('tenants')->count())->toBe(0);
});

it('needs a custom amount for a plan with a custom quote', function (): void {
    Notification::fake();
    provPlan('enterprise', prices: ['USD' => ['monthly' => null, 'annual' => null]]);

    expect(fn () => app(ProvisionTenant::class)->handle(provActor(), provInput(['planCode' => 'enterprise', 'billing' => 'offline'])))
        ->toThrow(DomainRuleViolation::class);
    expect(provPlatformDb()->table('tenants')->count())->toBe(0);

    $in = provInput(['planCode' => 'enterprise', 'billing' => 'offline', 'customAmount' => 250000]);
    app(ProvisionTenant::class)->handle(provActor(), $in);
    expect((int) provPlatformDb()->table('subscriptions')->where('tenant_id', $in->tenantId)->value('custom_amount'))->toBe(250000);
});
