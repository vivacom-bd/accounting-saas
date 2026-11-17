<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Contracts\IdGenerator;
use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Creates a staff member from the server console (`admin:create`). There is no signed-in actor, so the event is
 * `staff.bootstrapped` with the new admin as its own actor. They enrol TOTP on first sign-in.
 */
final class BootstrapAdminUser
{
    public function __construct(private readonly AdminAuditWriter $audit, private readonly IdGenerator $ids) {}

    public function __invoke(string $email, string $name, AdminRole $role, string $password): AdminUser
    {
        $email = mb_strtolower(trim($email));

        return AdminTransaction::run(function () use ($email, $name, $role, $password): AdminUser {
            if (AdminUser::query()->email($email)->exists()) {
                throw DomainRuleViolation::single('email_taken', 'A staff member with this email already exists.');
            }

            $admin = new AdminUser;
            $admin->forceFill([
                'id' => $this->ids->next(), 'email' => $email, 'name' => $name, 'role' => $role,
                'password' => $password, 'is_active' => true,
            ])->save();

            $this->audit->record(
                new AdminActor($admin->id, $role, requestId: 'console'), 'staff.bootstrapped', null, 'admin_user', $admin->id,
                after: ['email' => $email, 'name' => $name, 'role' => $role->value],
            );

            return $admin;
        });
    }
}
