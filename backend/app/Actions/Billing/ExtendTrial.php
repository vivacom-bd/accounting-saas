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
use DateTimeImmutable;

/**
 * Gives a trialing tenant more days (10 §3.2). The new end is the later of the current trial end and now, plus the days,
 * written to the subscription AND to `tenants.trial_ends_at`, which is what the lifecycle scheduler reads; the lifecycle
 * stays `trial`. Only while the tenant is in `trial` (once the trial ran out unpaid it is read-only: convert or
 * reactivate instead).
 */
final class ExtendTrial
{
    public const int MAX_DAYS = 90;

    public function __construct(
        private readonly SubscriptionSupport $support,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function __invoke(AdminActor $actor, string $tenantId, int $days, ?string $reason = null): Subscription
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw DomainRuleViolation::single('trial_extension_invalid', 'A trial can be extended by 1 to '.self::MAX_DAYS.' days at a time.');
        }

        return AdminTransaction::run(function () use ($actor, $tenantId, $days, $reason): Subscription {
            $tenant = $this->support->lockTenant($tenantId);
            $sub = $this->support->live($tenantId);
            if ($sub->status !== 'trialing' || $tenant->lifecycle !== LifecycleState::Trial->value) {
                throw DomainRuleViolation::single('trial_not_active', 'This organisation is not in a trial.');
            }
            $this->support->requireGatewayFor($sub->collection);

            $now = $this->support->now();
            $current = $sub->trial_ends_at ?? ($tenant->trial_ends_at !== null ? new DateTimeImmutable($tenant->trial_ends_at) : $now);
            $newEnd = ($current > $now ? $current : $now)->modify("+{$days} days");

            if ($sub->collection === 'stripe') {
                $this->support->mirror($sub, $this->support->gateway()->extendTrial((string) $sub->stripe_subscription_id, $newEnd));
            } else {
                $sub->forceFill(['trial_ends_at' => $newEnd, 'current_period_end' => $newEnd]);
            }
            $sub->save();

            AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->update(['trial_ends_at' => $sub->trial_ends_at ?? $newEnd]);

            $this->audit->record(
                $actor, 'subscription.trial_extended', $tenantId, 'subscription', $sub->id,
                ['trial_ends_at' => $current->format(DATE_ATOM)], ['trial_ends_at' => ($sub->trial_ends_at ?? $newEnd)->format(DATE_ATOM), 'days' => $days], $reason,
            );

            return $sub;
        });
    }
}
