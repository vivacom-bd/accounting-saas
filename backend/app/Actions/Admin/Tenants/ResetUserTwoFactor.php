<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Clears a tenant member's 2FA so they enrol again at next sign-in (support, lost device). The write happens in
 * sysops.admin_reset_two_factor, which refuses anyone without a membership in this tenant; the audit row is in the
 * same platform transaction. The user account is global: the reset applies in every organisation they belong to.
 */
final class ResetUserTwoFactor
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $tenantId, string $userId, ?string $reason = null): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $userId, $reason): void {
            if (! $db->table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->exists()) {
                throw DomainRuleViolation::single('not_found', 'This person is not in the organisation.');
            }
            /** @var object{done: bool} $row */
            $row = $db->selectOne('SELECT sysops.admin_reset_two_factor(?, ?) AS done', [$tenantId, $userId]);
            if (! $row->done) {
                throw DomainRuleViolation::single('two_factor_not_enabled', 'This person has no two-factor authentication to reset.');
            }
            $this->audit->record($actor, 'tenant.user_two_factor_reset', $tenantId, 'user', $userId, ['two_factor' => true], ['two_factor' => false], $reason);
        });
    }
}
