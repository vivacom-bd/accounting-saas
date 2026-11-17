<?php

declare(strict_types=1);

namespace App\Actions\Admin\Plans;

use App\Models\Billing\Plan;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Takes a plan off sale. Its versions stay: existing subscribers keep what they bought. */
final class ArchivePlan
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function __invoke(AdminActor $actor, Plan $plan): Plan
    {
        return AdminTransaction::run(function () use ($actor, $plan): Plan {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_active) {
                $locked->forceFill(['is_active' => false])->save();
                $this->audit->record($actor, 'plan.archived', null, 'plan', $locked->id, before: ['is_active' => true], after: ['is_active' => false]);
            }

            return $locked;
        });
    }
}
