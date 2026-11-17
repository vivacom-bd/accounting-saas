<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Coupon;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminTransaction;
use DateTimeInterface;

/** The subscription tab (10 §3.2): the live subscription, else the latest cancelled one, else null. */
final class TenantSubscriptionView
{
    public function __construct(private readonly SubscriptionSupport $support) {}

    /** @return array<string, mixed>|null */
    public function forTenant(string $tenantId): ?array
    {
        if (! AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->exists()) {
            throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        }
        $sub = $this->support->liveOrNull($tenantId, false)
            ?? Subscription::query()->where('tenant_id', $tenantId)->orderByDesc('created_at')->first();

        return $sub === null ? null : $this->view($sub);
    }

    /** @return array<string, mixed> */
    public function view(Subscription $sub): array
    {
        $version = $this->support->versionOf($sub);
        $plan = Plan::query()->whereKey($version->plan_id)->firstOrFail();
        $at = fn (?DateTimeInterface $t): ?string => $t?->format(DATE_ATOM);

        return [
            'id' => $sub->id, 'tenant_id' => $sub->tenant_id,
            'plan_code' => $plan->code, 'plan_name' => $plan->name, 'plan_version' => $version->version, 'plan_version_id' => $version->id,
            'collection' => $sub->collection, 'status' => $sub->status, 'interval' => $sub->interval, 'currency' => $sub->currency,
            'custom_amount' => $sub->custom_amount, 'quantity' => $sub->quantity, 'monthly_amount' => $this->support->monthlyOf($sub),
            'trial_ends_at' => $at($sub->trial_ends_at), 'current_period_end' => $at($sub->current_period_end),
            'cancel_at' => $at($sub->cancel_at), 'pause_until' => $at($sub->pause_until),
            'coupon_code' => $sub->coupon_id === null ? null : Coupon::query()->whereKey($sub->coupon_id)->value('code'),
            'stripe_subscription_id' => $sub->stripe_subscription_id, 'created_at' => $at($sub->created_at),
        ];
    }
}
