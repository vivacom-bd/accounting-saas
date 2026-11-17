<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\BillingGateway;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Billing\Prices;
use App\Models\Billing\Coupon;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanVersion;
use App\Support\Admin\AdminTransaction;

/**
 * Mirrors the catalogue to Stripe (`stripe:sync-catalogue`, plan Task 11): one product per version of an active plan
 * (code `<plan>-v<n>`), one price per version x currency x interval that has a price, one coupon per active unexpired
 * coupon (id = its code). Ids are stored as they come back (`plan_versions.stripe_product_id`, `stripe_prices`,
 * `coupons.stripe_coupon_id`), so a run that stops halfway resumes and a finished catalogue makes no gateway call at all.
 * Add-ons are not synced: their price column holds all currencies and has no Stripe mapping yet. A system job: no staff
 * member, so no admin audit row.
 */
final class SyncBillingCatalogue
{
    private const array INTERVALS = ['monthly' => 'month', 'annual' => 'year'];

    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    /** @return array{products: int, prices: int, coupons: int} counts created in this run */
    public function __invoke(): array
    {
        $db = AdminTransaction::connection();
        $counts = ['products' => 0, 'prices' => 0, 'coupons' => 0];

        foreach (Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('code')->get() as $plan) {
            foreach (PlanVersion::query()->where('plan_id', $plan->id)->orderBy('version')->get() as $version) {
                $productId = $version->stripe_product_id;
                if ($productId === null) {
                    $productId = $this->gateway->upsertProduct("{$plan->code}-v{$version->version}", "{$plan->name} v{$version->version}");
                    $version->forceFill(['stripe_product_id' => $productId])->save();
                    $counts['products']++;
                }

                foreach (Prices::CURRENCIES as $currency) {
                    foreach (self::INTERVALS as $key => $interval) {
                        $amount = Prices::of($version->prices, $currency, $key);
                        $has = $db->table('stripe_prices')->where('plan_version_id', $version->id)->where('currency', $currency)->where('interval', $interval)->exists();
                        if ($amount === null || $has) {
                            continue;
                        }
                        $db->table('stripe_prices')->insert([
                            'id' => $this->ids->next(), 'plan_version_id' => $version->id, 'currency' => $currency, 'interval' => $interval,
                            'amount' => $amount, 'stripe_price_id' => $this->gateway->upsertPrice($productId, $currency, $interval, $amount),
                            'created_at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
                        ]);
                        $counts['prices']++;
                    }
                }
            }
        }

        $now = $this->clock->now();
        $coupons = Coupon::query()->where('is_active', true)->whereNull('stripe_coupon_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))->orderBy('code')->get();
        foreach ($coupons as $coupon) {
            $stripeId = $this->gateway->upsertCoupon([
                'code' => $coupon->code, 'percent' => $coupon->percent, 'amount' => $coupon->amount, 'currency' => $coupon->currency,
                'duration' => $coupon->duration, 'duration_months' => $coupon->duration_months, 'max_redemptions' => $coupon->max_redemptions,
                'expires_at' => $coupon->expires_at,
            ]);
            $coupon->forceFill(['stripe_coupon_id' => $stripeId])->save();
            $counts['coupons']++;
        }

        return $counts;
    }
}
