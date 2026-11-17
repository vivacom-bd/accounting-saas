<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Domain\Admin\AdminPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Queries\Admin\Tenants\TenantUsers;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminTransaction;

/** Shared checks before staff impersonate someone: the ability, an open tenant, an active member (read through sysops). */
final class ImpersonationTarget
{
    public function __construct(private readonly TenantUsers $users) {}

    /** Lifecycles in which nobody opens the books (as ResolveTenant). */
    private const array CLOSED = ['cancelled', 'deleted'];

    /** @param list<string> $abilities every one is needed */
    public function require(AdminActor $actor, array $abilities, string $tenantId, string $userId): void
    {
        foreach ($abilities as $ability) {
            if (! AdminPermissions::allows($actor->role, $ability)) {
                throw DomainRuleViolation::single('forbidden', 'Your role cannot impersonate tenant users.');
            }
        }

        $lifecycle = AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->value('lifecycle');
        if (! is_string($lifecycle)) {
            throw DomainRuleViolation::single('not_found', 'Tenant not found.');
        }
        if (in_array($lifecycle, self::CLOSED, true)) {
            throw DomainRuleViolation::single('tenant_unavailable', 'This organisation is no longer available.');
        }

        $member = $this->users->one($tenantId, $userId);
        if ($member === null || $member['status'] !== 'active') {
            throw DomainRuleViolation::single('not_found', 'This person is not an active member of the organisation.');
        }
    }
}
