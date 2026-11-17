<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminIpRules;
use App\Support\Admin\AdminRequestContext;
use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\Hash;

/**
 * The password step of staff sign-in (plan Task 3). It signs nobody in: the caller records the returned admin as
 * pending until the TOTP step. Unknown email, wrong password and inactive account give the same answer and cost the
 * same hash check; each is recorded in the admin audit chain without a signed-in actor.
 */
final class AttemptAdminLogin
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function __invoke(string $email, string $password, AdminRequestContext $context): AdminUser
    {
        $email = mb_strtolower(trim($email));
        $admin = AdminUser::query()->email($email)->first();
        // Always one hash check, so response time does not tell whether the email exists.
        $matches = Hash::check($password, $admin->password ?? self::dummyHash());

        if ($admin === null || ! $matches || ! $admin->is_active) {
            $reason = match (true) {
                $admin === null => 'unknown_email',
                ! $matches => 'wrong_password',
                default => 'inactive',
            };
            AdminTransaction::run(fn () => $this->audit->recordAnonymous(
                $context, 'auth.login_failed', 'admin_user', $admin?->id, after: ['email' => $email], reason: $reason,
            ));

            throw DomainRuleViolation::single('invalid_credentials', 'These credentials do not match our records.');
        }

        // Only someone who knows the password learns that their address is not allowed.
        if (! AdminIpRules::permits($admin->ip_allowlist, $context->ip)) {
            AdminTransaction::run(fn () => $this->audit->recordAnonymous(
                $context, 'auth.login_blocked', 'admin_user', $admin->id, reason: 'ip_not_allowed',
            ));

            throw AdminIpBlocked::make();
        }

        return $admin;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('admin-login-timing-equaliser');
    }
}
