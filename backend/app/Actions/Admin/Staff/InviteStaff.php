<?php

declare(strict_types=1);

namespace App\Actions\Admin\Staff;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Notifications\AdminInvitation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Creates a staff member with a random unusable password and mails a 24 h set-password link. Only the token's SHA-256
 * is stored. They enrol TOTP at first sign-in. The mail goes out after the transaction commits.
 */
final class InviteStaff
{
    public function __construct(
        private readonly AdminAuditWriter $audit,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    public function handle(AdminActor $actor, string $email, string $name, AdminRole $role, string $inviterName): AdminUser
    {
        $email = mb_strtolower(trim($email));
        $token = Str::random(64);

        $admin = AdminTransaction::run(function () use ($actor, $email, $name, $role, $token): AdminUser {
            if (AdminUser::query()->email($email)->exists()) {
                throw DomainRuleViolation::single('email_taken', 'A staff member with this email already exists.');
            }

            $admin = new AdminUser;
            $admin->forceFill([
                'id' => $this->ids->next(), 'email' => $email, 'name' => $name, 'role' => $role,
                'password' => Str::random(64), 'is_active' => true,
                'password_set_token_hash' => hash('sha256', $token),
                'password_set_expires_at' => $this->clock->now()->modify('+'.AdminInvitation::VALID_HOURS.' hours'),
            ])->save();

            $this->audit->record($actor, 'staff.invited', null, 'admin_user', $admin->id, null,
                ['email' => $email, 'name' => $name, 'role' => $role->value]);

            return $admin;
        });

        Notification::route('mail', $email)->notify(new AdminInvitation($token, $name, $inviterName));

        return $admin->refresh();
    }
}
