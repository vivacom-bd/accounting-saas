<?php

declare(strict_types=1);

namespace App\Actions\Admin\Approvals;

use App\Contracts\Clock;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Spends an approved request, once, for the staff member who asked for it, when what they are about to do matches it. */
final class ConsumeApproval
{
    public function __construct(private readonly AdminAuditWriter $audit, private readonly Clock $clock) {}

    /** @param  array<string, mixed>  $mustMatch  subject fields the approval must carry with equal values */
    public function handle(AdminActor $requester, ApprovalKind $kind, string $approvalId, array $mustMatch): AdminApproval
    {
        return AdminTransaction::run(function () use ($requester, $kind, $approvalId, $mustMatch): AdminApproval {
            $approval = AdminApproval::query()->whereKey($approvalId)->lockForUpdate()->first();
            $now = $this->clock->now();

            if (
                $approval === null
                || $approval->kind !== $kind
                || $approval->requested_by !== $requester->id
                || $approval->effectiveStatus($now) !== 'approved'
                || array_intersect_key($approval->subject, $mustMatch) !== $mustMatch
            ) {
                throw DomainRuleViolation::single('approval_required', 'A matching, approved request is required first.');
            }

            $approval->forceFill(['status' => 'executed', 'executed_at' => $now])->save();
            $this->audit->record($requester, 'approval.executed', $approval->tenant_id, 'admin_approval', $approval->id,
                ['status' => 'approved'], ['status' => 'executed', 'kind' => $kind->value]);

            return $approval->refresh();
        });
    }
}
