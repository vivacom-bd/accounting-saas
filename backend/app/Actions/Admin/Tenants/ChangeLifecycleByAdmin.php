<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use App\Actions\Platform\ChangeTenantLifecycle;
use App\Domain\Platform\LifecycleState;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;

/**
 * A staff member moves a tenant along its lifecycle from the tenant page (plan Task 7). Cancelling schedules the
 * tenant's deletion, so it is not offered here: it goes through the two-person deletion flow (Task 18). Every other
 * move is checked, recorded and audited by ChangeTenantLifecycle.
 */
final class ChangeLifecycleByAdmin
{
    public function __construct(private readonly ChangeTenantLifecycle $change) {}

    public function handle(AdminActor $actor, string $tenantId, LifecycleState $to, string $reason): void
    {
        if ($to === LifecycleState::Cancelled) {
            throw DomainRuleViolation::single('use_deletion_flow', 'Cancelling an organisation schedules its deletion; request it through the deletion flow.');
        }

        $this->change->handle($actor, $tenantId, $to, $reason);
    }
}
