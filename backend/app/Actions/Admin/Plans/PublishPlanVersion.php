<?php

declare(strict_types=1);

namespace App\Actions\Admin\Plans;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Billing\PlanEntitlements;
use App\Domain\Billing\PlanLimits;
use App\Domain\Billing\Prices;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanVersion;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Editing a plan means publishing a new version (10 §3.3): existing subscribers keep theirs until migrated, and an
 * effective version can never change (trigger LG011). `$effectiveFrom` is a date; null means effective immediately.
 */
final class PublishPlanVersion
{
    public function __construct(
        private readonly AdminAuditWriter $audit,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<array-key, mixed>  $prices
     * @param  array<array-key, mixed>  $limits
     * @param  array<array-key, mixed>  $entitlements
     */
    public function __invoke(
        AdminActor $actor,
        Plan $plan,
        array $prices,
        array $limits,
        array $entitlements,
        int $trialDays,
        ?DateTimeImmutable $effectiveFrom,
    ): PlanVersion {
        return AdminTransaction::run(function () use ($actor, $plan, $prices, $limits, $entitlements, $trialDays, $effectiveFrom): PlanVersion {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw DomainRuleViolation::single('plan_archived', 'This plan is archived; it cannot get new versions.');
            }

            $version = $this->insert($actor, $locked, $prices, $limits, $entitlements, $trialDays, $effectiveFrom);

            $this->audit->record(
                $actor, 'plan.version_published', null, 'plan', $locked->id,
                after: ['code' => $locked->code, 'version' => $version->version, 'effective_from' => $version->effective_from->format(DATE_ATOM)],
            );

            return $version;
        });
    }

    /**
     * The next version of a plan, validated. Runs inside the caller's AdminTransaction; the caller audits.
     *
     * @param  array<array-key, mixed>  $prices
     * @param  array<array-key, mixed>  $limits
     * @param  array<array-key, mixed>  $entitlements
     */
    public function insert(
        AdminActor $actor,
        Plan $plan,
        array $prices,
        array $limits,
        array $entitlements,
        int $trialDays,
        ?DateTimeImmutable $effectiveFrom,
    ): PlanVersion {
        $prices = Prices::validate($prices);
        $limits = PlanLimits::validate($limits);
        $entitlements = PlanEntitlements::validate($entitlements);
        if ($trialDays < 0 || $trialDays > 365) {
            throw DomainRuleViolation::single('plan_trial_invalid', 'Trial length must be between 0 and 365 days.');
        }

        $now = $this->clock->now();
        $effective = $this->effectiveInstant($effectiveFrom, $now);
        $next = 1 + (int) PlanVersion::query()->where('plan_id', $plan->id)->max('version');

        $row = new PlanVersion;
        $row->forceFill([
            'id' => $this->ids->next(), 'plan_id' => $plan->id, 'version' => $next,
            'prices' => $prices, 'limits' => $limits, 'entitlements' => $entitlements,
            'trial_days' => $trialDays, 'effective_from' => $effective, 'created_by' => $actor->id, 'created_at' => $now,
        ])->save();

        return $row;
    }

    private function effectiveInstant(?DateTimeImmutable $requested, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($requested === null) {
            return $now;
        }
        $utc = new DateTimeZone('UTC');
        $day = $requested->setTimezone($utc)->setTime(0, 0);
        if ($day < $now->setTimezone($utc)->setTime(0, 0)) {
            throw DomainRuleViolation::single('plan_version_date_invalid', 'A plan version cannot take effect in the past.');
        }

        // Today means "now": the version is effective as soon as it is saved.
        return $day <= $now ? $now : $day;
    }
}
