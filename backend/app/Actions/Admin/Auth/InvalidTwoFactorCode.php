<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Domain\Shared\DomainRuleViolation;

/** 422 `invalid_two_factor_code`: the TOTP or recovery code did not match. */
final class InvalidTwoFactorCode
{
    public static function make(): DomainRuleViolation
    {
        return DomainRuleViolation::single('invalid_two_factor_code', 'The two-factor code is not valid.');
    }
}
