<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminIpRules;
use App\Support\Admin\AdminTransaction;

/** Changes a staff member's name, role or IP allowlist. Fields absent from `$changes` are left alone. */
final class UpdateStaff
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    /** @param  array{name?: string, role?: AdminRole, ip_allowlist?: list<string>}  $changes */
    public function handle(AdminActor $actor, string $id, array $changes): AdminUser
    {
        return AdminTransaction::run(function () use ($actor, $id, $changes): AdminUser {
            $admin = StaffGuards::lock($id);

            if (isset($changes['role']) && $changes['role'] !== AdminRole::Owner) {
                StaffGuards::assertNotLastOwner($admin);
            }
            if (isset($changes['ip_allowlist']) && $admin->id === $actor->id && ! AdminIpRules::permits($changes['ip_allowlist'], $actor->ip)) {
                throw DomainRuleViolation::single('admin_ip_self_lockout', 'That allowlist would lock you out from your current address.');
            }

            $before = self::snapshot($admin);
            if (isset($changes['role']) && $changes['role'] !== $admin->role) {
                $changes['auth_epoch'] = $admin->auth_epoch + 1;
            }
            $admin->forceFill($changes)->save();
            $after = self::snapshot($admin);

            $this->audit->record($actor, 'staff.updated', null, 'admin_user', $admin->id, $before, $after);

            return $admin;
        });
    }

    /** @return array{name: string, role: string, ip_allowlist: list<string>} */
    private static function snapshot(AdminUser $admin): array
    {
        return ['name' => $admin->name, 'role' => $admin->role->value, 'ip_allowlist' => $admin->ip_allowlist];
    }
}
