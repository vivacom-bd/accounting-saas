<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Actions\Platform\ChangeTenantLifecycle;
use App\Contracts\BillingGateway;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Billing\MrrMovement;
use App\Domain\Billing\Prices;
use App\Domain\Platform\LifecycleState;
use App\Domain\Platform\TenantLifecycle;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanVersion;
use App\Models\Billing\Subscription;
use App\Models\Billing\SubscriptionEvent;
use App\Services\Billing\Dto\GatewaySubscription;
use App\Support\Actor;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * What every subscription Action shares (plan Task 11). Everything here runs inside the caller's AdminTransaction.
 * Not an Action: it writes nothing on its own authority and records no admin audit event.
 */
final class SubscriptionSupport
{
    /** Statuses that carry MRR: a trial has not been sold yet, a pause bills nothing. */
    public const array MRR_STATUSES = ['active', 'past_due'];

    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly BillingGateway $gateway,
        private readonly ChangeTenantLifecycle $lifecycle,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    public function gateway(): BillingGateway
    {
        return $this->gateway;
    }

    /** Stripe collection needs a configured gateway before anything else is checked. */
    public function requireGatewayFor(string $collection): void
    {
        if ($collection === 'stripe' && ! $this->gateway->isConfigured()) {
            throw NullBillingGateway::notConfigured();
        }
    }

    /** @return object{id: string, name: string, lifecycle: string, trial_ends_at: string|null} */
    public function lockTenant(string $tenantId): object
    {
        /** @var object{id: string, name: string, lifecycle: string, trial_ends_at: string|null}|null $tenant */
        $tenant = AdminTransaction::connection()->selectOne('SELECT id, name, lifecycle, trial_ends_at FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);

        return $tenant ?? throw DomainRuleViolation::single('not_found', 'Organisation not found.');
    }

    public function liveOrNull(string $tenantId, bool $lock = true): ?Subscription
    {
        $query = Subscription::query()->where('tenant_id', $tenantId)->where('status', '<>', 'cancelled');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function live(string $tenantId, bool $lock = true): Subscription
    {
        return $this->liveOrNull($tenantId, $lock)
            ?? throw DomainRuleViolation::single('subscription_not_found', 'This organisation has no live subscription.');
    }

    /**
     * The plan and the version in force now.
     *
     * @return array{0: Plan, 1: PlanVersion}
     */
    public function resolvePlan(string $planCode): array
    {
        $plan = Plan::query()->whereRaw('lower(code) = ?', [strtolower($planCode)])->first()
            ?? throw DomainRuleViolation::single('plan_not_found', "There is no plan with code {$planCode}.");
        if (! $plan->is_active) {
            throw DomainRuleViolation::single('plan_archived', 'This plan is archived and cannot be sold.');
        }
        $version = PlanVersion::query()->where('plan_id', $plan->id)->where('effective_from', '<=', $this->now())->orderByDesc('version')->first()
            ?? throw DomainRuleViolation::single('plan_not_found', 'This plan has no version in force yet.');

        return [$plan, $version];
    }

    /** The plan code a subscription is on. */
    public function planCodeOf(Subscription $sub): string
    {
        $planId = PlanVersion::query()->whereKey($sub->plan_version_id)->value('plan_id');

        return (string) Plan::query()->whereKey($planId)->value('code');
    }

    public function versionOf(Subscription $sub): PlanVersion
    {
        return $sub->planVersion ?? throw new LogicException("Subscription {$sub->id} has no plan version.");
    }

    /** The amount billed per interval: the custom amount, else the plan price times quantity. */
    public function amount(PlanVersion $version, string $currency, string $interval, ?int $customAmount, int $quantity): int
    {
        if ($customAmount !== null) {
            return $customAmount;
        }
        $price = Prices::of($version->prices, $currency, $interval === 'year' ? 'annual' : 'monthly');
        if ($price === null) {
            throw DomainRuleViolation::single('subscription_price_missing', "This plan has no {$currency} {$interval}ly price; give a custom amount.");
        }

        return $price * $quantity;
    }

    public function monthlyOf(Subscription $sub): int
    {
        return MrrMovement::monthly($this->amount($this->versionOf($sub), $sub->currency, $sub->interval, $sub->custom_amount, $sub->quantity), $sub->interval);
    }

    /** The MRR this subscription carries now (null when its status carries none). */
    public function mrrNow(Subscription $sub): ?int
    {
        return in_array($sub->status, self::MRR_STATUSES, true) ? $this->monthlyOf($sub) : null;
    }

    public function stripePriceId(string $planVersionId, string $currency, string $interval): string
    {
        $id = AdminTransaction::connection()->table('stripe_prices')
            ->where('plan_version_id', $planVersionId)->where('currency', $currency)->where('interval', $interval)->value('stripe_price_id');

        return is_string($id) ? $id
            : throw DomainRuleViolation::single('billing_price_not_synced', 'This price has not been synced to Stripe yet. Run stripe:sync-catalogue.');
    }

    public function addInterval(DateTimeImmutable $from, string $interval): DateTimeImmutable
    {
        return $from->modify($interval === 'year' ? '+1 year' : '+1 month');
    }

    /** Records an MRR movement from `$old` to `$new` monthly amounts; nothing when they are equal. */
    public function recordMovement(Subscription $sub, ?int $old, ?int $new, ?DateTimeImmutable $at = null): void
    {
        $returning = $old === null && SubscriptionEvent::query()->where('tenant_id', $sub->tenant_id)->where('kind', 'churn')->exists();
        $movement = MrrMovement::of($old, $new, $returning);
        if ($movement === null) {
            return;
        }
        (new SubscriptionEvent)->forceFill([
            'id' => $this->ids->next(), 'subscription_id' => $sub->id, 'tenant_id' => $sub->tenant_id, 'kind' => $movement['kind'],
            'currency' => $sub->currency, 'mrr_delta' => $movement['delta'], 'occurred_at' => ($at ?? $this->now()),
        ])->save();
    }

    /** Copies the gateway's answer into the local row (status and dates only; plan and money stay ours). */
    public function mirror(Subscription $sub, GatewaySubscription $remote): void
    {
        $sub->forceFill([
            'status' => $remote->status,
            'current_period_end' => $remote->currentPeriodEnd,
            'trial_ends_at' => $remote->trialEnd ?? $sub->trial_ends_at,
            'cancel_at' => $remote->cancelAt,
            'pause_until' => $remote->status === 'paused' ? $sub->pause_until : null,
        ]);
    }

    /**
     * Brings the tenant to `$to` when the machine allows it and it is not there already. Returns whether it moved.
     *
     * @param  object{id: string, lifecycle: string}  $tenant
     */
    public function moveTenant(AdminActor|Actor $by, object $tenant, LifecycleState $to, string $reason, ?DateTimeImmutable $at = null): bool
    {
        $from = LifecycleState::from((string) AdminTransaction::connection()->table('tenants')->where('id', $tenant->id)->value('lifecycle'));
        if ($from === $to || ! TenantLifecycle::canMove($from, $to)) {
            return false;
        }
        $this->lifecycle->handle($by, $tenant->id, $to, $reason, $at);

        return true;
    }

    public function ownerEmail(string $tenantId): string
    {
        $email = AdminTransaction::connection()->selectOne(
            "SELECT u.email FROM tenant_users tu JOIN users u ON u.id = tu.user_id WHERE tu.tenant_id = ? AND tu.role = 'owner' AND tu.status = 'active' ORDER BY tu.created_at LIMIT 1",
            [$tenantId],
        );

        return $email === null ? '' : (string) ((array) $email)['email'];
    }
}
