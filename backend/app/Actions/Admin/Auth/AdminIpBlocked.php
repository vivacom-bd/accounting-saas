<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Domain\Shared\DomainRuleViolation;

/** 403 `admin_ip_blocked`: the request's address is outside the global or the staff member's allowlist. */
final class AdminIpBlocked
{
    public static function make(): DomainRuleViolation
    {
        return DomainRuleViolation::single('admin_ip_blocked', 'Admin access is not allowed from this address.');
    }
}
