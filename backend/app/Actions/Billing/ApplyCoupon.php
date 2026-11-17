<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Subscription;
use App\Services\Billing\CouponRedemption;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Applies a coupon code to a live subscription (10 §3.2, §3.4): one coupon per subscription. The coupon must be active,
 * unexpired, have redemptions left, match the subscription's currency (amount coupons) and plan. Stripe collection also
 * needs the coupon synced and applies it at the gateway. The discount is recorded, not deducted from MRR (MrrMovement).
 */
final class ApplyCoupon
{
    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly CouponRedemption $coupons,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function __invoke(AdminActor $actor, string $tenantId, string $code, ?string $reason = null): Subscription
    {
        return AdminTransaction::run(function () use ($actor, $tenantId, $code, $reason): Subscription {
            $this->support->lockTenant($tenantId);
            $sub = $this->support->live($tenantId);
            if ($sub->coupon_id !== null) {
                throw DomainRuleViolation::single('coupon_already_applied', 'This subscription already has a coupon.');
            }
            $this->support->requireGatewayFor($sub->collection);

            $coupon = $this->coupons->lockAndCheck($code, $tenantId, $this->support->planCodeOf($sub), $sub->currency, $this->support->now());

            if ($sub->collection === 'stripe') {
                if ($coupon->stripe_coupon_id === null) {
                    throw DomainRuleViolation::single('coupon_not_synced', 'This coupon has not been synced to Stripe yet. Run stripe:sync-catalogue.');
                }
                $this->support->mirror($sub, $this->support->gateway()->applyCoupon((string) $sub->stripe_subscription_id, $coupon->stripe_coupon_id));
            }
            $sub->forceFill(['coupon_id' => $coupon->id])->save();
            $this->coupons->redeem($coupon);

            $this->audit->record($actor, 'subscription.coupon_applied', $tenantId, 'subscription', $sub->id, null, ['coupon' => $coupon->code], $reason);

            return $sub;
        });
    }
}
