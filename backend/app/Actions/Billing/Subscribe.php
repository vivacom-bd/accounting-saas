<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\IdGenerator;
use App\Domain\Billing\Prices;
use App\Domain\Platform\LifecycleState;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Coupon;
use App\Models\Billing\Subscription;
use App\Services\Billing\CouponRedemption;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;

/**
 * Puts a tenant on a plan (plan Task 11, 10_SaaS_Admin §3.2). `offline` collection is purely local state: the tenant is
 * invoiced by hand, no gateway call is made. `stripe` collection creates the subscription at the gateway and mirrors the
 * state it returns; it is refused with `billing_not_configured` when no keys exist.
 *
 * A tenant still inside its trial gets a `trialing` subscription ending with the trial and no MRR yet; otherwise it is
 * `active` for one interval, an `new`/`reactivation` MRR event is recorded, and the tenant lifecycle moves to active.
 * Only one live subscription per tenant exists (partial unique index; checked here first for a clean error).
 */
final class Subscribe
{
    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly CouponRedemption $coupons,
        private readonly AdminAuditWriter $audit,
        private readonly IdGenerator $ids,
    ) {}

    public function __invoke(
        AdminActor $actor,
        string $tenantId,
        string $planCode,
        string $interval,
        string $currency,
        string $collection,
        ?int $customAmount = null,
        ?string $couponCode = null,
        ?string $reason = null,
    ): Subscription {
        if (! in_array($interval, ['month', 'year'], true) || ! in_array($currency, Prices::CURRENCIES, true)
            || ! in_array($collection, ['stripe', 'offline'], true) || ($customAmount !== null && $customAmount < 0)) {
            throw DomainRuleViolation::single('subscription_invalid', 'Interval, currency, collection or custom amount is not valid.');
        }
        $this->support->requireGatewayFor($collection);

        return AdminTransaction::run(function () use ($actor, $tenantId, $planCode, $interval, $currency, $collection, $customAmount, $couponCode, $reason): Subscription {
            $tenant = $this->support->lockTenant($tenantId);
            if ($this->support->liveOrNull($tenantId) !== null) {
                throw DomainRuleViolation::single('subscription_exists', 'This organisation already has a live subscription. Change its plan instead.');
            }
            $now = $this->support->now();

            [$plan, $version] = $this->support->resolvePlan($planCode);
            $this->support->amount($version, $currency, $interval, $customAmount, 1);   // refuses a missing price early

            $coupon = $couponCode === null || trim($couponCode) === ''
                ? null
                : $this->coupons->lockAndCheck($couponCode, $tenantId, $plan->code, $currency, $now);

            $trialEnd = $tenant->lifecycle === LifecycleState::Trial->value && $tenant->trial_ends_at !== null
                ? new DateTimeImmutable($tenant->trial_ends_at)
                : null;
            $trialing = $trialEnd !== null && $trialEnd > $now;

            $sub = new Subscription;
            $sub->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $tenantId, 'plan_version_id' => $version->id, 'collection' => $collection,
                'interval' => $interval, 'currency' => $currency, 'custom_amount' => $customAmount, 'quantity' => 1,
                'status' => $trialing ? 'trialing' : 'active',
                'trial_ends_at' => $trialing ? $trialEnd : null,
                'current_period_end' => $trialing ? $trialEnd : $this->support->addInterval($now, $interval),
                'coupon_id' => $coupon?->id,
            ]);

            if ($collection === 'stripe') {
                $this->createAtGateway($sub, $tenant, $coupon, $trialing ? $trialEnd : null);
            }
            $sub->save();
            $sub->setRelation('planVersion', $version);

            if ($coupon !== null) {
                $this->coupons->redeem($coupon);
            }
            if (in_array($sub->status, SubscriptionSupport::MRR_STATUSES, true)) {
                $this->support->recordMovement($sub, null, $this->support->monthlyOf($sub), $now);
            }

            $this->audit->record($actor, 'subscription.created', $tenantId, 'subscription', $sub->id, null, [
                'plan_code' => $plan->code, 'plan_version' => $version->version, 'interval' => $interval, 'currency' => $currency,
                'collection' => $collection, 'custom_amount' => $customAmount, 'status' => $sub->status, 'coupon' => $coupon?->code,
            ], $reason);

            if ($sub->status === 'active') {
                $this->support->moveTenant($actor, $tenant, LifecycleState::Active, 'subscription.created');
            }

            return $sub;
        });
    }

    /** @param  object{id: string, name: string}  $tenant */
    private function createAtGateway(Subscription $sub, object $tenant, ?Coupon $coupon, ?DateTimeImmutable $trialEnd): void
    {
        $priceId = $this->support->stripePriceId($sub->plan_version_id, $sub->currency, $sub->interval);
        if ($coupon !== null && $coupon->stripe_coupon_id === null) {
            throw DomainRuleViolation::single('coupon_not_synced', 'This coupon has not been synced to Stripe yet. Run stripe:sync-catalogue.');
        }

        $gateway = $this->support->gateway();
        $customerId = $gateway->ensureCustomer($tenant->id, $tenant->name, $this->support->ownerEmail($tenant->id));
        $remote = $gateway->createSubscription($customerId, $priceId, $trialEnd, $coupon?->stripe_coupon_id, 1);

        $sub->forceFill(['stripe_customer_id' => $customerId, 'stripe_subscription_id' => $remote->id]);
        $this->support->mirror($sub, $remote);
    }
}
