<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Contracts\ApprovalExecutor;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;

/** Carries out an approved legal_hold_release; idempotent (see SetLegalHold::release). */
final class LegalHoldReleaseExecutor implements ApprovalExecutor
{
    public function __construct(private readonly SetLegalHold $hold) {}

    public function kind(): ApprovalKind
    {
        return ApprovalKind::LegalHoldRelease;
    }

    public function execute(AdminActor $approver, AdminApproval $approval): void
    {
        $this->hold->release(
            $approver,
            $approval->tenant_id ?? throw DomainRuleViolation::single('not_found', 'Organisation not found.'),
            $approval->reason,
        );
    }
}
