<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Platform\LifecycleState;
use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Cancels a live subscription (10 §3.2). At period end: it stays as it is (active, MRR counted) with `cancel_at` set to
 * the end of the current period; for offline collection the daily `subscriptions:advance` applies it then, for Stripe
 * the gateway ends it and its webhook (Task 12) does. Immediately: the subscription is cancelled now, the churn MRR event
 * recorded and the tenant moved to `cancelled` (which schedules its deletion, G5-G7), where the lifecycle allows it.
 */
final class CancelSubscription
{
    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function __invoke(AdminActor $actor, string $tenantId, bool $atPeriodEnd, ?string $reason = null): Subscription
    {
        return AdminTransaction::run(function () use ($actor, $tenantId, $atPeriodEnd, $reason): Subscription {
            $tenant = $this->support->lockTenant($tenantId);
            $sub = $this->support->live($tenantId);
            $this->support->requireGatewayFor($sub->collection);

            $before = ['status' => $sub->status, 'cancel_at' => $sub->cancel_at?->format(DATE_ATOM)];
            $oldMrr = $this->support->mrrNow($sub);

            if ($sub->collection === 'stripe') {
                $this->support->mirror($sub, $this->support->gateway()->cancel((string) $sub->stripe_subscription_id, $atPeriodEnd));
            } elseif ($atPeriodEnd) {
                $sub->forceFill(['cancel_at' => $sub->current_period_end ?? $this->support->now()]);
            } else {
                $sub->forceFill(['status' => 'cancelled', 'cancel_at' => null, 'pause_until' => null]);
            }
            $sub->save();

            if ($atPeriodEnd) {
                $this->audit->record($actor, 'subscription.cancel_scheduled', $tenantId, 'subscription', $sub->id, $before, ['cancel_at' => $sub->cancel_at?->format(DATE_ATOM)], $reason);

                return $sub;
            }

            $sub->status = 'cancelled';
            $sub->save();
            if ($oldMrr !== null) {
                $this->support->recordMovement($sub, $oldMrr, null);
            }
            $this->audit->record($actor, 'subscription.cancelled', $tenantId, 'subscription', $sub->id, $before, ['status' => 'cancelled'], $reason);
            $this->support->moveTenant($actor, $tenant, LifecycleState::Cancelled, 'subscription.cancelled');

            return $sub;
        });
    }
}
