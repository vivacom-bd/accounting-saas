<?php

declare(strict_types=1);

namespace App\Actions\Admin\Plans;

use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Plan;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;

/** A new plan with its first version (a plan without one cannot be sold). */
final class CreatePlan
{
    public function __construct(
        private readonly AdminAuditWriter $audit,
        private readonly IdGenerator $ids,
        private readonly PublishPlanVersion $versions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $prices
     * @param  array<array-key, mixed>  $limits
     * @param  array<array-key, mixed>  $entitlements
     */
    public function __invoke(
        AdminActor $actor,
        string $code,
        string $name,
        ?string $description,
        int $sort,
        array $prices,
        array $limits,
        array $entitlements,
        int $trialDays,
        ?DateTimeImmutable $effectiveFrom,
    ): Plan {
        $code = mb_strtolower(trim($code));

        return AdminTransaction::run(function () use ($actor, $code, $name, $description, $sort, $prices, $limits, $entitlements, $trialDays, $effectiveFrom): Plan {
            if (Plan::query()->whereRaw('lower(code) = ?', [$code])->exists()) {
                throw DomainRuleViolation::single('plan_code_taken', 'A plan with this code already exists.');
            }

            $plan = new Plan;
            $plan->forceFill([
                'id' => $this->ids->next(), 'code' => $code, 'name' => $name, 'description' => $description,
                'is_active' => true, 'sort' => $sort,
            ])->save();

            $version = $this->versions->insert($actor, $plan, $prices, $limits, $entitlements, $trialDays, $effectiveFrom);

            $this->audit->record(
                $actor, 'plan.created', null, 'plan', $plan->id,
                after: ['code' => $code, 'name' => $name, 'version' => $version->version, 'prices' => $version->prices, 'limits' => $version->limits],
            );

            return $plan;
        });
    }
}
