<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Moves a live subscription to the current version of a plan, optionally to the other billing interval (10 §3.2).
 * The currency never changes. A custom amount is only kept when it is passed again. Stripe collection calls the gateway
 * (prorated unless told otherwise) and mirrors its answer. The MRR movement (expansion or contraction) is recorded only
 * while the subscription carries MRR (active or past due); changing nothing records nothing and calls nothing.
 */
final class ChangePlan
{
    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function __invoke(
        AdminActor $actor,
        string $tenantId,
        string $planCode,
        ?string $interval = null,
        ?int $customAmount = null,
        bool $prorate = true,
        ?string $reason = null,
    ): Subscription {
        if ($interval !== null && ! in_array($interval, ['month', 'year'], true)) {
            throw DomainRuleViolation::single('subscription_invalid', 'Interval must be month or year.');
        }
        if ($customAmount !== null && $customAmount < 0) {
            throw DomainRuleViolation::single('subscription_invalid', 'The custom amount cannot be negative.');
        }

        return AdminTransaction::run(function () use ($actor, $tenantId, $planCode, $interval, $customAmount, $prorate, $reason): Subscription {
            $this->support->lockTenant($tenantId);
            $sub = $this->support->live($tenantId);
            $this->support->requireGatewayFor($sub->collection);

            [$plan, $version] = $this->support->resolvePlan($planCode);
            $newInterval = $interval ?? $sub->interval;
            $this->support->amount($version, $sub->currency, $newInterval, $customAmount, $sub->quantity);

            if ($version->id === $sub->plan_version_id && $newInterval === $sub->interval && $customAmount === $sub->custom_amount) {
                return $sub;
            }

            $before = ['plan_code' => $this->support->planCodeOf($sub), 'plan_version_id' => $sub->plan_version_id, 'interval' => $sub->interval, 'custom_amount' => $sub->custom_amount];
            $oldMrr = $this->support->mrrNow($sub);

            if ($sub->collection === 'stripe') {
                $priceId = $this->support->stripePriceId($version->id, $sub->currency, $newInterval);
                $remote = $this->support->gateway()->changePrice((string) $sub->stripe_subscription_id, $priceId, $prorate);
                $this->support->mirror($sub, $remote);
            }

            $sub->forceFill(['plan_version_id' => $version->id, 'interval' => $newInterval, 'custom_amount' => $customAmount]);
            $sub->save();
            $sub->load('planVersion');

            if ($oldMrr !== null) {
                $this->support->recordMovement($sub, $oldMrr, $this->support->mrrNow($sub));
            }

            $this->audit->record($actor, 'subscription.plan_changed', $tenantId, 'subscription', $sub->id, $before, [
                'plan_code' => $plan->code, 'plan_version_id' => $version->id, 'interval' => $newInterval, 'custom_amount' => $customAmount, 'prorate' => $prorate,
            ], $reason);

            return $sub;
        });
    }
}
