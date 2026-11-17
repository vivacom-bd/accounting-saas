<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Actions\Admin\Approvals\RequestApproval;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;

/**
 * Asks a second staff member to approve write-mode impersonation of one member of one tenant (plan Task 14, T6). The
 * approval's subject is exactly {tenant_id, user_id}; StartImpersonation spends it once, for the requester only.
 */
final class RequestImpersonationWrite
{
    public function __construct(private readonly ImpersonationTarget $target, private readonly RequestApproval $request) {}

    public function handle(AdminActor $actor, string $tenantId, string $userId, string $reason): AdminApproval
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw DomainRuleViolation::single('reason_required', 'Say why you need to change data as this person.');
        }
        $this->target->require($actor, ['impersonate.read', 'impersonate.write.request'], $tenantId, $userId);

        return $this->request->handle($actor, ApprovalKind::ImpersonateWrite, $tenantId, ['tenant_id' => $tenantId, 'user_id' => $userId], $reason);
    }
}
