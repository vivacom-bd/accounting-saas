<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Deactivates a staff member. Their open sessions end on the next request (RequireAdminTwoFactor signs out an
 * inactive admin) and they cannot sign in. The last active owner cannot be deactivated.
 */
final class DeactivateStaff
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $id): AdminUser
    {
        return AdminTransaction::run(function () use ($actor, $id): AdminUser {
            $admin = StaffGuards::lock($id);
            if (! $admin->is_active) {
                return $admin;
            }

            StaffGuards::assertNotLastOwner($admin);
            $admin->forceFill(['is_active' => false, 'password_set_token_hash' => null, 'password_set_expires_at' => null])->save();

            $this->audit->record($actor, 'staff.deactivated', null, 'admin_user', $admin->id, ['is_active' => true], ['is_active' => false]);

            return $admin;
        });
    }
}
