<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Platform\LifecycleState;
use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionSupport;
use App\Support\Actor;
use App\Support\Admin\AdminTransaction;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The daily step for offline (invoiced) subscriptions, run by `subscriptions:advance` as the system (no staff member, so
 * no admin audit row; the lifecycle event and the MRR event are the record). In order:
 *   1. a cancel-at-period-end whose date has come: cancelled, churn recorded at that date, tenant moved to cancelled;
 *   2. a pause whose end has come: active again;
 *   3. an active subscription whose period has run out: rolled forward by whole intervals (renewal; the invoice itself
 *      is raised by hand).
 * Stripe-collected subscriptions are left alone: Stripe ends and renews them and its webhooks (Task 12) mirror that.
 * Every step re-reads the row under lock, so a second run (or a staff action in between) adds nothing.
 */
final class AdvanceSubscriptions
{
    private const int PAGE = 200;

    public function __construct(private readonly SubscriptionSupport $support) {}

    /** @return array{cancelled: int, woken: int, rolled: int} */
    public function __invoke(): array
    {
        $now = $this->support->now();

        return [
            'cancelled' => $this->each(
                fn (): Builder => $this->offline()->where('status', '<>', 'cancelled')->where('cancel_at', '<=', $now),
                fn (string $id): bool => $this->cancel($id, $now),
            ),
            'woken' => $this->each(
                fn (): Builder => $this->offline()->where('status', 'paused')->where('pause_until', '<=', $now),
                fn (string $id): bool => $this->wake($id, $now),
            ),
            'rolled' => $this->each(
                fn (): Builder => $this->offline()->where('status', 'active')->where('current_period_end', '<=', $now),
                fn (string $id): bool => $this->roll($id, $now),
            ),
        ];
    }

    /** @return Builder<Subscription> */
    private function offline(): Builder
    {
        return Subscription::query()->where('collection', 'offline');
    }

    /**
     * @param  Closure(): Builder<Subscription>  $query
     * @param  Closure(string): bool  $handle
     */
    private function each(Closure $query, Closure $handle): int
    {
        $after = '00000000-0000-0000-0000-000000000000';
        $done = 0;
        do {
            $ids = $query()->where('id', '>', $after)->orderBy('id')->limit(self::PAGE)->pluck('id')->all();
            foreach ($ids as $id) {
                $after = (string) $id;
                $done += $handle($after) ? 1 : 0;
            }
        } while (count($ids) === self::PAGE);

        return $done;
    }

    private function cancel(string $id, DateTimeImmutable $now): bool
    {
        return AdminTransaction::run(function () use ($id, $now): bool {
            $sub = Subscription::query()->whereKey($id)->lockForUpdate()->first();
            if ($sub === null || $sub->status === 'cancelled' || $sub->cancel_at === null || $sub->cancel_at > $now) {
                return false;
            }
            $tenant = $this->support->lockTenant($sub->tenant_id);
            $at = DateTimeImmutable::createFromInterface($sub->cancel_at);
            $oldMrr = $this->support->mrrNow($sub);

            $sub->forceFill(['status' => 'cancelled', 'pause_until' => null])->save();
            if ($oldMrr !== null) {
                $this->support->recordMovement($sub, $oldMrr, null, $at);
            }
            $this->support->moveTenant(Actor::system($sub->tenant_id), $tenant, LifecycleState::Cancelled, 'subscription.cancel_at_period_end', $at);

            return true;
        });
    }

    private function wake(string $id, DateTimeImmutable $now): bool
    {
        return AdminTransaction::run(function () use ($id, $now): bool {
            $sub = Subscription::query()->whereKey($id)->lockForUpdate()->first();
            if ($sub === null || $sub->status !== 'paused' || $sub->pause_until === null || $sub->pause_until > $now) {
                return false;
            }
            $sub->forceFill(['status' => 'active', 'pause_until' => null])->save();

            return true;
        });
    }

    private function roll(string $id, DateTimeImmutable $now): bool
    {
        return AdminTransaction::run(function () use ($id, $now): bool {
            $sub = Subscription::query()->whereKey($id)->lockForUpdate()->first();
            if ($sub === null || $sub->status !== 'active' || $sub->current_period_end === null || $sub->current_period_end > $now) {
                return false;
            }
            $end = DateTimeImmutable::createFromInterface($sub->current_period_end);
            while ($end <= $now) {
                $end = $this->support->addInterval($end, $sub->interval);
            }
            $sub->forceFill(['current_period_end' => $end])->save();

            return true;
        });
    }
}
