<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Clears a staff member's TOTP secret and recovery codes. Their open sessions end on the next request
 * (RequireAdminTwoFactor signs out an admin without confirmed 2FA) and the next sign-in enrols afresh.
 */
final class ResetStaffTwoFactor
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function handle(AdminActor $actor, string $id): AdminUser
    {
        return AdminTransaction::run(function () use ($actor, $id): AdminUser {
            $admin = StaffGuards::lock($id);
            $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'auth_epoch' => $admin->auth_epoch + 1])->save();

            $this->audit->record($actor, 'staff.two_factor_reset', null, 'admin_user', $admin->id);

            return $admin;
        });
    }
}
