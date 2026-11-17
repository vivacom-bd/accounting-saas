<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pauses billing on an active subscription, until a date or until resumed (10 §3.2). The tenant's lifecycle is not
 * touched (a pause is a commercial arrangement, not a suspension). No MRR event is recorded: MRR reports must count only
 * `active` and `past_due` subscriptions (SubscriptionSupport::MRR_STATUSES), so a paused one drops out by its status.
 * Offline subscriptions wake up by themselves on `pause_until` (subscriptions:advance).
 */
final class PauseSubscription
{
    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function __invoke(AdminActor $actor, string $tenantId, ?DateTimeImmutable $until, ?string $reason = null): Subscription
    {
        $until = $until?->setTimezone(new DateTimeZone('UTC'));

        return AdminTransaction::run(function () use ($actor, $tenantId, $until, $reason): Subscription {
            $this->support->lockTenant($tenantId);
            $sub = $this->support->live($tenantId);
            if ($sub->status !== 'active') {
                throw DomainRuleViolation::single('subscription_not_pausable', 'Only an active subscription can be paused.');
            }
            if ($until !== null && $until <= $this->support->now()) {
                throw DomainRuleViolation::single('subscription_pause_invalid', 'The pause must end in the future.');
            }
            $this->support->requireGatewayFor($sub->collection);

            if ($sub->collection === 'stripe') {
                $this->support->mirror($sub, $this->support->gateway()->pause((string) $sub->stripe_subscription_id, $until));
                $sub->forceFill(['pause_until' => $until]);
            } else {
                $sub->forceFill(['status' => 'paused', 'pause_until' => $until]);
            }
            $sub->save();

            $this->audit->record($actor, 'subscription.paused', $tenantId, 'subscription', $sub->id, ['status' => 'active'], ['status' => $sub->status, 'pause_until' => $until?->format(DATE_ATOM)], $reason);

            return $sub;
        });
    }
}
