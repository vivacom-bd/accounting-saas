<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Actions\Admin\Approvals\RequestApproval;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminTransaction;

/**
 * Asks for an organisation's deletion (plan Task 18, G7). Only opens the two-person approval; the approver's
 * TenantDeleteExecutor then cancels the tenant, which schedules the deletion 90 days out. Nothing is purged.
 */
final class RequestTenantDeletion
{
    public function __construct(private readonly RequestApproval $request) {}

    public function handle(AdminActor $actor, string $tenantId, string $reason): AdminApproval
    {
        $lifecycle = AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->value('lifecycle');
        if (! is_string($lifecycle)) {
            throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        }
        if ($lifecycle === 'deleted') {
            throw DomainRuleViolation::single('lifecycle_transition_invalid', 'This organisation was already deleted.');
        }

        return $this->request->handle($actor, ApprovalKind::TenantDelete, $tenantId, ['tenant_id' => $tenantId], $reason);
    }
}
