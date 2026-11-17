<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminRequestContext;
use App\Support\Admin\AdminTransaction;

/** Consumes an invitation token (once) and sets the staff member's password. No sign-in: they still pass TOTP. */
final class SetStaffPassword
{
    public function __construct(private readonly AdminAuditWriter $audit, private readonly Clock $clock) {}

    public function handle(AdminRequestContext $context, string $token, string $password): void
    {
        $invalid = DomainRuleViolation::single('invalid_token', 'This link is invalid or has expired.');

        AdminTransaction::run(function () use ($context, $token, $password, $invalid): void {
            $admin = AdminUser::query()->where('password_set_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($admin === null || $admin->password_set_expires_at === null || $admin->password_set_expires_at <= $this->clock->now() || ! $admin->is_active) {
                throw $invalid;
            }

            $admin->forceFill(['password' => $password, 'auth_epoch' => $admin->auth_epoch + 1, 'password_set_token_hash' => null, 'password_set_expires_at' => null])->save();

            $this->audit->record(new AdminActor($admin->id, $admin->role, $context->ip, $context->userAgent, $context->requestId),
                'staff.password_set', null, 'admin_user', $admin->id);
        });
    }
}
