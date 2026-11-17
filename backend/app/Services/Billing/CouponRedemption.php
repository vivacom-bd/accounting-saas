<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Coupon;
use App\Models\Billing\Subscription;
use DateTimeImmutable;

/**
 * The rules for redeeming a coupon on a subscription (10_SaaS_Admin §3.4): active, not expired, redemptions left, in the
 * subscription's currency for an amount coupon, valid for its plan, and a first-time customer when so restricted. Runs
 * inside the caller's AdminTransaction; the row stays locked so the redemption count cannot be overrun.
 */
final class CouponRedemption
{
    /** @throws DomainRuleViolation */
    public function lockAndCheck(string $code, string $tenantId, string $planCode, string $currency, DateTimeImmutable $now): Coupon
    {
        $coupon = Coupon::query()->whereRaw('upper(code) = ?', [mb_strtoupper(trim($code))])->lockForUpdate()->first()
            ?? throw DomainRuleViolation::single('coupon_not_found', 'There is no coupon with this code.');

        $fail = fn (string $c, string $m): DomainRuleViolation => DomainRuleViolation::single($c, $m);
        if (! $coupon->is_active) {
            throw $fail('coupon_inactive', 'This coupon has been archived.');
        }
        if ($coupon->expires_at !== null && $coupon->expires_at <= $now) {
            throw $fail('coupon_expired', 'This coupon has expired.');
        }
        if ($coupon->max_redemptions !== null && $coupon->redeemed_count >= $coupon->max_redemptions) {
            throw $fail('coupon_exhausted', 'This coupon has been redeemed the maximum number of times.');
        }
        if ($coupon->amount !== null && $coupon->currency !== $currency) {
            throw $fail('coupon_currency_mismatch', "This coupon is a fixed amount in {$coupon->currency}; the subscription bills in {$currency}.");
        }

        $restrictions = $coupon->restrictions;
        $plans = $restrictions['plan_codes'] ?? null;
        if (is_array($plans) && $plans !== [] && ! in_array(strtolower($planCode), array_map('strtolower', array_map('strval', $plans)), true)) {
            throw $fail('coupon_not_applicable', 'This coupon does not apply to this plan.');
        }
        if (($restrictions['new_customers_only'] ?? false) === true
            && Subscription::query()->where('tenant_id', $tenantId)->where('status', 'cancelled')->exists()) {
            throw $fail('coupon_not_applicable', 'This coupon is for new customers only.');
        }

        return $coupon;
    }

    public function redeem(Coupon $coupon): void
    {
        $coupon->forceFill(['redeemed_count' => $coupon->redeemed_count + 1])->save();
    }
}
