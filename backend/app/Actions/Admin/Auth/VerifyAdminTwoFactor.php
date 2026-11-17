<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminRequestContext;
use App\Support\Admin\AdminTransaction;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * The TOTP step of staff sign-in: a current code (replays refused by Fortify's provider) or a recovery code, which is
 * removed under a row lock so two requests cannot both spend it. On success the caller logs the admin guard in.
 */
final class VerifyAdminTwoFactor
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $totp,
        private readonly AdminAuditWriter $audit,
        private readonly Clock $clock,
    ) {}

    public function __invoke(AdminUser $admin, ?string $code, ?string $recoveryCode, AdminRequestContext $context): void
    {
        if ($admin->two_factor_confirmed_at === null || $admin->two_factor_secret === null) {
            throw DomainRuleViolation::single('admin_two_factor_required', 'Set up two-factor authentication to continue.');
        }

        $verified = AdminTransaction::run(function () use ($admin, $code, $recoveryCode, $context): bool {
            /** @var AdminUser $locked */
            $locked = AdminUser::query()->lockForUpdate()->findOrFail($admin->id);
            $usedRecovery = false;

            if ($code !== null && $code !== '') {
                $ok = $this->totp->verify((string) $locked->two_factor_secret, $code);
            } else {
                $remaining = RecoveryCodes::consume($locked->two_factor_recovery_codes ?? [], (string) $recoveryCode);
                $ok = $remaining !== null;
                if ($ok) {
                    $locked->two_factor_recovery_codes = $remaining;
                    $usedRecovery = true;
                }
            }

            if (! $ok) {
                $this->audit->recordAnonymous($context, 'auth.two_factor_failed', 'admin_user', $admin->id, reason: $code !== null && $code !== '' ? 'totp' : 'recovery_code');

                return false;
            }

            $locked->last_login_at = $this->clock->now();
            $locked->save();
            $actor = $context->actorFor($locked);
            if ($usedRecovery) {
                $this->audit->record($actor, 'auth.recovery_code_used', null, 'admin_user', $admin->id, after: ['remaining' => count($locked->two_factor_recovery_codes ?? [])]);
            }
            $this->audit->record($actor, 'auth.login', null, 'admin_user', $admin->id, after: ['method' => $usedRecovery ? 'recovery_code' : 'totp']);

            return true;
        });

        if (! $verified) {
            throw InvalidTwoFactorCode::make();
        }
    }
}
