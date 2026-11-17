<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/** What needs a second staff member's sign-off (10_SaaS_Admin §6, plan Task 4). Stored in admin_approvals.kind. */
enum ApprovalKind: string
{
    case Refund = 'refund';
    case ImpersonateWrite = 'impersonate_write';
    case PlanMigration = 'plan_migration';
    case TenantDelete = 'tenant_delete';
    case LegalHoldRelease = 'legal_hold_release';

    /** @return list<AdminRole> roles that may approve or reject this kind */
    public function deciders(): array
    {
        return match ($this) {
            self::Refund, self::PlanMigration => [AdminRole::Owner, AdminRole::Finance],
            self::ImpersonateWrite, self::TenantDelete, self::LegalHoldRelease => [AdminRole::Owner],
        };
    }

    /** Approval runs the action itself; false when the requester consumes the approval later (impersonate_write, Task 14). */
    public function autoExecute(): bool
    {
        return $this !== self::ImpersonateWrite;
    }
}
