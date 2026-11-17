<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Platform\LifecycleState;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Ends a trial early and starts the paid subscription (10 §3.2; plan Task 11). Offline: local state only, the first
 * period runs from now. Stripe: the trial end is moved to now at the gateway, whose answer is mirrored. Once the
 * subscription is active the `new` (or `reactivation`) MRR event is recorded and the tenant lifecycle moves to active.
 */
final class ConvertTrial
{
    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function __invoke(AdminActor $actor, string $tenantId, ?string $reason = null): Subscription
    {
        return AdminTransaction::run(function () use ($actor, $tenantId, $reason): Subscription {
            $tenant = $this->support->lockTenant($tenantId);
            $sub = $this->support->live($tenantId);
            if ($sub->status !== 'trialing') {
                throw DomainRuleViolation::single('trial_not_active', 'This subscription is not in a trial.');
            }
            $this->support->requireGatewayFor($sub->collection);
            $now = $this->support->now();

            if ($sub->collection === 'stripe') {
                $this->support->mirror($sub, $this->support->gateway()->extendTrial((string) $sub->stripe_subscription_id, $now));
            } else {
                $sub->forceFill(['status' => 'active', 'current_period_end' => $this->support->addInterval($now, $sub->interval)]);
            }
            $sub->save();

            if (in_array($sub->status, SubscriptionSupport::MRR_STATUSES, true)) {
                $this->support->recordMovement($sub, null, $this->support->monthlyOf($sub), $now);
            }
            $this->audit->record($actor, 'subscription.trial_converted', $tenantId, 'subscription', $sub->id, ['status' => 'trialing'], ['status' => $sub->status], $reason);

            if ($sub->status === 'active') {
                $this->support->moveTenant($actor, $tenant, LifecycleState::Active, 'subscription.trial_converted');
            }

            return $sub;
        });
    }
}
