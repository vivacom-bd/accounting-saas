<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use LogicException;

/**
 * Disables a member's access to this tenant (their next tenant request is refused: ResolveTenant needs an active
 * membership). The write is sysops.admin_set_member_status, which never disables the last active owner and leaves
 * invited memberships to the invitation flow. Disabling an already disabled member changes and records nothing.
 */
final class DisableTenantUser
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $tenantId, string $userId, string $reason): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $userId, $reason): void {
            /** @var object{outcome: string} $row */
            $row = $db->selectOne("SELECT sysops.admin_set_member_status(?, ?, 'disabled') AS outcome", [$tenantId, $userId]);

            match ($row->outcome) {
                'updated' => $this->audit->record(
                    $actor, 'tenant.user_disabled', $tenantId, 'user', $userId, ['status' => 'active'], ['status' => 'disabled'], $reason,
                ),
                'unchanged' => null,
                'not_member' => throw DomainRuleViolation::single('not_found', 'This person is not in the organisation.'),
                'pending_invitation' => throw DomainRuleViolation::single('membership_pending', 'This person has not accepted their invitation yet; revoke the invitation instead.'),
                'last_owner' => throw DomainRuleViolation::single('last_org_owner', 'This is the last active owner of the organisation.'),
                default => throw new LogicException("Unexpected member status outcome {$row->outcome}."),
            };
        });
    }
}
