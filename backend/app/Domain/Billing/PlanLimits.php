<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\DomainRuleViolation;

/** Included quantities of a plan version. Every key is required; null means unlimited. Hard: companies, users, api_clients (G8). */
final class PlanLimits
{
    public const array KEYS = ['companies', 'users', 'api_clients', 'journal_lines_per_month', 'storage_mb', 'api_calls_per_day'];

    /** @return array<string, int|null> */
    public static function validate(mixed $limits): array
    {
        if (! is_array($limits) || array_diff(array_keys($limits), self::KEYS) !== [] || array_diff(self::KEYS, array_keys($limits)) !== []) {
            throw DomainRuleViolation::single('plan_limits_invalid', 'Limits need exactly: '.implode(', ', self::KEYS).'.');
        }
        $clean = [];
        foreach (self::KEYS as $key) {
            $value = $limits[$key];
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw DomainRuleViolation::single('plan_limits_invalid', "Limit {$key} must be a whole number, zero or more, or null for unlimited.");
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
