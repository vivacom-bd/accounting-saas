<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermissions;
use App\Domain\Admin\AdminRole;

/* Plan Task 3: ability → roles. Written out independently of the class so a change to either is deliberate. */
const ADMIN_ABILITY_ROLES = [
    'tenants.view' => ['owner', 'finance', 'sales', 'support', 'engineer', 'readonly'],
    'tenants.provision' => ['owner', 'sales', 'support'],
    'tenants.lifecycle' => ['owner', 'finance', 'support'],
    'tenants.notes' => ['owner', 'finance', 'sales', 'support', 'engineer'],
    'billing.view' => ['owner', 'finance', 'readonly'],
    'billing.manage' => ['owner', 'finance'],
    'billing.refund.request' => ['owner', 'finance'],
    'plans.view' => ['owner', 'finance', 'sales', 'support', 'engineer', 'readonly'],
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

dataset('ability_role', function (): Generator {
    foreach (ADMIN_ABILITY_ROLES as $ability => $roles) {
        foreach (AdminRole::cases() as $role) {
            yield "{$ability} × {$role->value}" => [$ability, $role, in_array($role->value, $roles, true)];
        }
    }
});

it('grants each ability to exactly its roles', function (string $ability, AdminRole $role, bool $expected): void {
    expect(AdminPermissions::allows($role, $ability))->toBe($expected);
})->with('ability_role');

it('knows exactly the listed abilities', function (): void {
    expect(array_keys(AdminPermissions::ABILITIES))->toEqualCanonicalizing(array_keys(ADMIN_ABILITY_ROLES));
});

it('denies an unknown ability to every role', function (): void {
    foreach (AdminRole::cases() as $role) {
        expect(AdminPermissions::allows($role, 'tenants.destroy'))->toBeFalse();
    }
});

it('lists the abilities a role holds', function (): void {
    expect(AdminPermissions::abilitiesFor(AdminRole::Readonly))->toBe(['tenants.view', 'billing.view', 'plans.view', 'audit.view'])
        ->and(AdminPermissions::abilitiesFor(AdminRole::Owner))->toHaveCount(count(ADMIN_ABILITY_ROLES));
});
