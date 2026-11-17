<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Actions\Platform\ChangeTenantLifecycle;
use App\Contracts\ApprovalExecutor;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Platform\LifecycleState;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminTransaction;

/**
 * Carries out an approved tenant_delete: moves the tenant to cancelled, which schedules the deletion (+90 days, blocked
 * under legal hold) inside ChangeTenantLifecycle. Idempotent: an already cancelled tenant is left alone, so a retry
 * after a partial failure changes nothing twice. Nothing is purged (G7).
 */
final class TenantDeleteExecutor implements ApprovalExecutor
{
    public function __construct(private readonly ChangeTenantLifecycle $lifecycle) {}

    public function kind(): ApprovalKind
    {
        return ApprovalKind::TenantDelete;
    }

    public function execute(AdminActor $approver, AdminApproval $approval): void
    {
        $tenantId = $approval->tenant_id ?? throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        $db = AdminTransaction::connection();

        $state = $db->table('tenants')->where('id', $tenantId)->value('lifecycle');
        if (! is_string($state)) {
            throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        }
        if ($state !== LifecycleState::Cancelled->value) {
            $this->lifecycle->handle($approver, $tenantId, LifecycleState::Cancelled, $approval->reason);
        }

        $db->table('tenant_deletions')->where('tenant_id', $tenantId)->whereIn('status', ['scheduled', 'blocked'])
            ->whereNull('approved_by')->update(['approved_by' => $approver->id]);
    }
}
