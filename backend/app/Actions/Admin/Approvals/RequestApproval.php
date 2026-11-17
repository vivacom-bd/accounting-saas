<?php

declare(strict_types=1);

namespace App\Actions\Admin\Approvals;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Admin\ApprovalKind;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Opens a two-person approval; it lapses after `admin.approval_ttl_hours`. Callers check their own request ability. */
final class RequestApproval
{
    public function __construct(
        private readonly AdminAuditWriter $audit,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    /** @param  array<string, mixed>  $subject */
    public function handle(AdminActor $actor, ApprovalKind $kind, ?string $tenantId, array $subject, string $reason): AdminApproval
    {
        return AdminTransaction::run(function () use ($actor, $kind, $tenantId, $subject, $reason): AdminApproval {
            $now = $this->clock->now();
            $approval = AdminApproval::query()->create([
                'id' => $this->ids->next(),
                'kind' => $kind->value,
                'tenant_id' => $tenantId,
                'subject' => $subject,
                'reason' => $reason,
                'requested_by' => $actor->id,
                'status' => 'pending',
                'expires_at' => $now->modify('+'.(int) config('admin.approval_ttl_hours', 24).' hours'),
                'created_at' => $now,
            ])->refresh();

            $this->audit->record($actor, 'approval.requested', $tenantId, 'admin_approval', $approval->id, null,
                ['kind' => $kind->value, 'subject' => $subject], $reason);

            return $approval;
        });
    }
}
