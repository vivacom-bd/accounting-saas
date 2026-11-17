<?php

declare(strict_types=1);

namespace App\Actions\Admin\Approvals;

use App\Contracts\ApprovalExecutor;
use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use LogicException;

/**
 * Approves or rejects a pending request: never by its requester, only by a role the kind allows. Approving a kind that
 * auto-executes runs its tagged executor in the same transaction, so a failed executor leaves the request pending.
 */
final class DecideApproval
{
    public function __construct(private readonly AdminAuditWriter $audit, private readonly Clock $clock) {}

    public function handle(AdminActor $actor, string $approvalId, bool $approve, ?string $rejectionReason): AdminApproval
    {
        return AdminTransaction::run(function () use ($actor, $approvalId, $approve, $rejectionReason): AdminApproval {
            $approval = AdminApproval::query()->whereKey($approvalId)->lockForUpdate()->first()
                ?? throw DomainRuleViolation::single('not_found', 'Approval not found.');

            if ($approval->requested_by === $actor->id) {
                throw DomainRuleViolation::single('approval_self_decision', 'A request must be decided by someone other than its requester.');
            }
            if (! in_array($actor->role, $approval->kind->deciders(), true)) {
                throw DomainRuleViolation::single('forbidden', 'Your role cannot decide this kind of request.');
            }
            $now = $this->clock->now();
            if ($approval->effectiveStatus($now) !== 'pending') {
                throw DomainRuleViolation::single('approval_not_pending', 'This request is no longer pending.');
            }

            $approval->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'approved_by' => $actor->id,
                'rejection_reason' => $approve ? null : $rejectionReason,
                'decided_at' => $now,
            ])->save();
            $this->audit->record($actor, $approve ? 'approval.approved' : 'approval.rejected', $approval->tenant_id, 'admin_approval', $approval->id,
                ['status' => 'pending'], ['status' => $approval->status, 'kind' => $approval->kind->value], $approve ? $approval->reason : $rejectionReason);

            if ($approve && $approval->kind->autoExecute()) {
                $this->executor($approval)->execute($actor, $approval);
                $approval->forceFill(['status' => 'executed', 'executed_at' => $this->clock->now()])->save();
                $this->audit->record($actor, 'approval.executed', $approval->tenant_id, 'admin_approval', $approval->id,
                    ['status' => 'approved'], ['status' => 'executed', 'kind' => $approval->kind->value]);
            }

            return $approval->refresh();
        });
    }

    private function executor(AdminApproval $approval): ApprovalExecutor
    {
        foreach (app()->tagged('admin.approval_executors') as $executor) {
            if ($executor instanceof ApprovalExecutor && $executor->kind() === $approval->kind) {
                return $executor;
            }
        }

        throw new LogicException("No approval executor is registered for {$approval->kind->value}.");
    }
}
