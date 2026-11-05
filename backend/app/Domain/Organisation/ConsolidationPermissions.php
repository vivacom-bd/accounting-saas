<?php

declare(strict_types=1);

namespace App\Domain\Organisation;

/**
 * Consolidation rights live at organisation level (13 §23 D16.10), because a group spans companies and the company
 * permission matrix is per company. They derive from the organisation role in `tenant_users.role`, so there is no table
 * of grants and no backfill: owners and admins hold all of them, a plain member may read.
 *
 * On top of these, every use also needs `reports.view` in each member company (checked by the policy and the Actions).
 */
final class ConsolidationPermissions
{
    public const string VIEW = 'consolidation.view';

    public const string MANAGE = 'consolidation.manage';

    public const string RUN = 'consolidation.run';

    public const string APPROVE = 'consolidation.approve';

    /** The company permission needed in every member company. */
    public const string MEMBER_VIEW = 'reports.view';

    /** @return list<string> */
    public static function forOrgRole(?string $role): array
    {
        return match ($role) {
            'owner', 'admin' => [self::VIEW, self::MANAGE, self::RUN, self::APPROVE],
            'member' => [self::VIEW],
            default => [],
        };
    }

    public static function holds(?string $role, string $permission): bool
    {
        return in_array($permission, self::forOrgRole($role), true);
    }
}
