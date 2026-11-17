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
use Laravel\Fortify\RecoveryCode;

/**
 * Confirms a pending TOTP enrolment with a first code, issues eight single-use recovery codes (returned once, stored
 * only as hashes) and completes the sign-in: the caller logs the admin guard in.
 */
final class ConfirmAdminTwoFactor
{
    public const int RECOVERY_CODES = 8;

    public function __construct(
        private readonly TwoFactorAuthenticationProvider $totp,
        private readonly AdminAuditWriter $audit,
        private readonly Clock $clock,
    ) {}

    /** @return list<string> the recovery codes, shown to the staff member this once */
    public function __invoke(AdminUser $admin, string $code, AdminRequestContext $context): array
    {
        if ($admin->two_factor_confirmed_at !== null) {
            throw DomainRuleViolation::single('two_factor_already_confirmed', 'Two-factor authentication is already set up.');
        }
        if ($admin->two_factor_secret === null) {
            throw DomainRuleViolation::single('two_factor_not_started', 'Start two-factor setup first.');
        }
        if (! $this->totp->verify($admin->two_factor_secret, $code)) {
            AdminTransaction::run(fn () => $this->audit->recordAnonymous($context, 'auth.two_factor_failed', 'admin_user', $admin->id, reason: 'enrolment_code'));

            throw InvalidTwoFactorCode::make();
        }

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = RecoveryCode::generate();
        }

        AdminTransaction::run(function () use ($admin, $codes, $context): void {
            $now = $this->clock->now();
            $admin->forceFill([
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => array_map(RecoveryCodes::hash(...), $codes),
                'last_login_at' => $now,
            ])->save();
            $actor = $context->actorFor($admin);
            $this->audit->record($actor, 'auth.two_factor_confirmed', null, 'admin_user', $admin->id);
            $this->audit->record($actor, 'auth.login', null, 'admin_user', $admin->id, after: ['method' => 'totp_enrolment']);
        });

        return $codes;
    }
}
