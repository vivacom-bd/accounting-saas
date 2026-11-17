<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/**
 * What each platform staff role may do (10_SaaS_Admin §6, plan Task 3). Per-kind approval rules live with the
 * approvals engine; this is the coarse gate checked by the `admin.can:<ability>` middleware.
 */
final class AdminPermissions
{
    private const array ALL = ['owner', 'finance', 'sales', 'support', 'engineer', 'readonly'];

    /** ability => roles holding it */
    public const array ABILITIES = [
        'tenants.view' => self::ALL,
        'tenants.provision' => ['owner', 'sales', 'support'],
        'tenants.lifecycle' => ['owner', 'finance', 'support'],
        'tenants.notes' => ['owner', 'finance', 'sales', 'support', 'engineer'],
        'billing.view' => ['owner', 'finance', 'readonly'],
        'billing.manage' => ['owner', 'finance'],
        'billing.refund.request' => ['owner', 'finance'],
        'plans.manage' => ['owner', 'finance'],
        'coupons.manage' => ['owner', 'finance', 'sales'],
        'impersonate.read' => ['owner', 'support'],
        'impersonate.write.request' => ['owner', 'support'],
        'approvals.decide' => ['owner', 'finance'],
        'staff.manage' => ['owner'],
        'ops.view' => ['owner', 'engineer', 'support'],
        'ops.manage' => ['owner', 'engineer'],
        'flags.manage' => ['owner', 'engineer'],
        'compliance.manage' => ['owner', 'support'],
        'growth.manage' => ['owner', 'sales'],
        'audit.view' => ['owner', 'readonly', 'engineer'],
    ];

    /** Unknown abilities are denied. */
    public static function allows(AdminRole $role, string $ability): bool
    {
        return in_array($role->value, self::ABILITIES[$ability] ?? [], true);
    }

    /** @return list<string> in declaration order */
    public static function abilitiesFor(AdminRole $role): array
    {
        return array_values(array_filter(
            array_keys(self::ABILITIES),
            fn (string $ability): bool => self::allows($role, $ability),
        ));
    }
}
