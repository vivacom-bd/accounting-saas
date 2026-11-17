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
 * Ends a pause and withdraws a pending cancel-at-period-end (10 §3.2 "reactivate" for a live subscription). A subscription
 * that was cancelled for good is brought back by subscribing again, which records a reactivation. No MRR event: neither
 * a pause nor a scheduled cancellation ever moved MRR.
 */
final class ResumeSubscription
{
    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function __invoke(AdminActor $actor, string $tenantId, ?string $reason = null): Subscription
    {
        return AdminTransaction::run(function () use ($actor, $tenantId, $reason): Subscription {
            $this->support->lockTenant($tenantId);
            $sub = $this->support->live($tenantId);
            if ($sub->status !== 'paused' && $sub->cancel_at === null) {
                throw DomainRuleViolation::single('subscription_not_resumable', 'Nothing to resume: the subscription is neither paused nor set to cancel.');
            }
            $this->support->requireGatewayFor($sub->collection);

            $before = ['status' => $sub->status, 'cancel_at' => $sub->cancel_at?->format(DATE_ATOM), 'pause_until' => $sub->pause_until?->format(DATE_ATOM)];
            if ($sub->collection === 'stripe') {
                $this->support->mirror($sub, $this->support->gateway()->resume((string) $sub->stripe_subscription_id));
            } else {
                $sub->forceFill(['status' => $sub->status === 'paused' ? 'active' : $sub->status, 'cancel_at' => null, 'pause_until' => null]);
            }
            $sub->save();

            $this->audit->record($actor, 'subscription.resumed', $tenantId, 'subscription', $sub->id, $before, ['status' => $sub->status, 'cancel_at' => null], $reason);

            return $sub;
        });
    }
}
