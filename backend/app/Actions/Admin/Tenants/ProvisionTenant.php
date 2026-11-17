<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use App\Actions\Admin\Tenants\Inputs\ProvisionTenantInput;
use App\Actions\Billing\Subscribe;
use App\Actions\Organisation\IssueInvitation;
use App\Actions\Platform\ChangeTenantLifecycle;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Billing\Prices;
use App\Domain\Platform\LifecycleState;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanVersion;
use App\Models\Platform\Tenant;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantSlug;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Provisions an organisation from the platform admin (plan Task 6, G3, T4): the tenant row and the owner invitation are
 * written by the tenant UnitOfWork on the app connection, which commits first; then one platform transaction records the
 * first lifecycle event and the admin audit event. Staff set no password and create no company: the owner accepts the
 * invitation and runs the normal onboarding.
 *
 * Idempotent on `$in->tenantId` (derived from the request's Idempotency-Key): a retry finds the tenant, completes the
 * platform part if the first attempt died between the two commits, and answers with the same tenant. The platform part also
 * starts the tenant's offline subscription through `Subscribe` (once). The chosen plan version stays in
 * `tenants.settings.plan_version_id` as the fallback source for entitlements.
 */
final class ProvisionTenant
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly TenantContext $tenant,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly IssueInvitation $invitations,
        private readonly ChangeTenantLifecycle $lifecycle,
        private readonly AdminAuditWriter $audit,
        private readonly IdGenerator $ids,
        private readonly Subscribe $subscribe,
    ) {}

    public function handle(AdminActor $admin, ProvisionTenantInput $in): ProvisionedTenant
    {
        $created = false;
        if (! $this->exists($in->tenantId)) {
            $created = $this->createTenant($admin, $in);
        }

        $result = $this->load($in->tenantId, $created);
        if (! $created) {
            $this->assertSameRequest($result, $in);
        }
        $this->recordPlatformSide($admin, $in, $result);

        return $result;
    }

    private function exists(string $tenantId): bool
    {
        return AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->exists();
    }

    /** @return bool true when this call made the tenant, false when a concurrent retry beat it to it */
    private function createTenant(AdminActor $admin, ProvisionTenantInput $in): bool
    {
        $version = $this->planVersion($in->planCode);
        $this->currencyFor($version, $in); // refuse an unpriced currency or interval before anything is written
        $now = $this->clock->now();
        $trial = $in->billing === 'trial';
        $trialEndsAt = $trial ? $now->modify('+'.($in->trialDays ?? $version->trial_days).' days') : null;
        $actor = new Actor(
            ActorType::Admin, $admin->id, $in->tenantId, ip: $admin->ip, userAgent: $admin->userAgent, requestId: $admin->requestId,
        );

        try {
            // The tenant side runs as the app role, never the platform role the admin request defaults to.
            $this->db->usingConnection('pgsql', fn () => $this->tenant->run($in->tenantId, fn () => $this->uow->run(
                $actor,
                function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in, $version, $now, $trial, $trialEndsAt): void {
                    $tenant = new Tenant;
                    $tenant->forceFill([
                        'id' => $in->tenantId,
                        'name' => $in->organisationName,
                        'slug' => TenantSlug::unique($in->organisationName),
                        'lifecycle' => $trial ? LifecycleState::Trial->value : LifecycleState::Active->value,
                        'lifecycle_since' => $now,
                        'country' => $in->country,
                        'trial_ends_at' => $trialEndsAt,
                        'settings' => ['plan_version_id' => $version->id, 'billing' => $in->billing],
                    ])->save();

                    $this->invitations->handle($actor, $audit, $in->ownerEmail, 'owner', null, [], null, 'The Ledgerly team');

                    $audit->add('organisation.provisioned', $tenant, after: [
                        'name' => $tenant->name, 'slug' => $tenant->slug, 'country' => $in->country, 'plan_version_id' => $version->id,
                        'billing' => $in->billing, 'owner_email' => mb_strtolower($in->ownerEmail),
                    ]);
                },
            )));
        } catch (UniqueConstraintViolationException $e) {
            if ($this->exists($in->tenantId)) {
                return false; // two attempts with one key raced; the other one made it
            }

            throw $e;
        }

        return true;
    }

    private function planVersion(string $code): PlanVersion
    {
        $plan = Plan::query()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->where('is_active', true)->first();
        $version = $plan === null ? null : PlanVersion::query()->where('plan_id', $plan->id)
            ->where('effective_from', '<=', $this->clock->now())->orderByDesc('version')->first();

        return $version ?? throw DomainRuleViolation::single('plan_not_found', 'Choose a plan that is on sale.');
    }

    /** The currency the subscription bills in: the request's, else the first of USD, KES, EUR the plan version prices. */
    private function currencyFor(PlanVersion $version, ProvisionTenantInput $in): string
    {
        if (! in_array($in->interval, ['month', 'year'], true)) {
            throw DomainRuleViolation::single('subscription_invalid', 'Choose a monthly or yearly interval.');
        }
        $priced = array_keys($version->prices);
        // jsonb does not keep key order, so "first" means the first of the billing currencies the plan prices (USD, KES, EUR).
        $currency = $in->currency ?? array_values(array_intersect(Prices::CURRENCIES, $priced))[0] ?? null;
        if (! is_string($currency) || ! in_array($currency, $priced, true)) {
            throw DomainRuleViolation::single('subscription_price_missing', 'This plan has no price in that currency.');
        }
        if ($in->customAmount === null && Prices::of($version->prices, $currency, $in->interval === 'year' ? 'annual' : 'monthly') === null) {
            throw DomainRuleViolation::single('subscription_price_missing', 'This plan is priced by quote; give a custom amount.');
        }

        return $currency;
    }

    private function load(string $tenantId, bool $created): ProvisionedTenant
    {
        $row = AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->first()
            ?? throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        /** @var array{plan_version_id?: string} $settings */
        $settings = json_decode((string) $row->settings, true) ?: [];
        $plan = AdminTransaction::connection()->table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->where('plan_versions.id', $settings['plan_version_id'] ?? null)->first(['plans.code', 'plans.name']);

        // Invitations are tenant data: read them as the app role inside the tenant.
        $invitation = $this->db->usingConnection('pgsql', fn () => $this->tenant->run($tenantId, fn () => $this->db->connection('pgsql')
            ->table('invitations')->where('role', 'owner')->orderBy('created_at')->first(['email', 'expires_at'])))
            ?? throw DomainRuleViolation::single('not_found', 'The owner invitation is missing.');

        return new ProvisionedTenant(
            $row->id, $row->name, $row->slug, $row->lifecycle, $row->country,
            $row->trial_ends_at === null ? null : new DateTimeImmutable($row->trial_ends_at),
            $plan->code ?? '', $plan->name ?? '', $invitation->email, new DateTimeImmutable($invitation->expires_at), $created,
        );
    }

    private function assertSameRequest(ProvisionedTenant $existing, ProvisionTenantInput $in): void
    {
        if ($existing->name !== $in->organisationName || $existing->country !== $in->country || $existing->ownerEmail !== mb_strtolower($in->ownerEmail)) {
            throw DomainRuleViolation::single('idempotency_key_reuse', 'This Idempotency-Key was already used for a different request.');
        }
    }

    /** The first lifecycle event and the admin audit event, once; a retry after a crash between the two commits completes it. */
    private function recordPlatformSide(AdminActor $admin, ProvisionTenantInput $in, ProvisionedTenant $result): void
    {
        AdminTransaction::run(function () use ($admin, $in, $result): void {
            // Serialise concurrent retries on the tenant row, so the event and the audit row are written once.
            AdminTransaction::connection()->table('tenants')->where('id', $in->tenantId)->lockForUpdate()->value('id');
            $done = AdminTransaction::connection()->table('tenant_lifecycle_events')->where('tenant_id', $in->tenantId)->exists();
            if (! $done) {
                $this->recordFirstEvents($admin, $in, $result);
            }
            $this->startSubscription($admin, $in);
        });
    }

    /** One subscription per provisioned tenant, ever: a retry (or a cancelled one later) never starts another. */
    private function startSubscription(AdminActor $admin, ProvisionTenantInput $in): void
    {
        if (AdminTransaction::connection()->table('subscriptions')->where('tenant_id', $in->tenantId)->exists()) {
            return;
        }
        $version = $this->planVersion($in->planCode);
        ($this->subscribe)(
            $admin, $in->tenantId, $in->planCode, $in->interval, $this->currencyFor($version, $in), 'offline', $in->customAmount,
            null, 'Provisioned from admin',
        );
    }

    private function recordFirstEvents(AdminActor $admin, ProvisionTenantInput $in, ProvisionedTenant $result): void
    {
        $state = LifecycleState::from($result->status);
        $this->lifecycle->recordInitial($admin, $in->tenantId, $state, 'Provisioned from admin', $this->clock->now());
        $this->audit->record(
            $admin, 'tenant.provisioned', $in->tenantId, 'tenant', $in->tenantId, null,
            [
                'name' => $result->name, 'slug' => $result->slug, 'country' => $result->country, 'lifecycle' => $result->status,
                'plan' => $result->planCode, 'billing' => $in->billing, 'owner_email' => $result->ownerEmail,
                'trial_ends_at' => $result->trialEndsAt?->format(DATE_ATOM),
            ],
            $in->note,
        );
        if ($in->note !== null && trim($in->note) !== '') {
            AdminTransaction::connection()->table('tenant_notes')->insert([
                'id' => $this->ids->next(), 'tenant_id' => $in->tenantId, 'admin_id' => $admin->id, 'body' => trim($in->note), 'pinned' => false,
                'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }
}
