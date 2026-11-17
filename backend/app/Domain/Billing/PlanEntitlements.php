<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\DomainRuleViolation;

/** Feature switches of a plan version. Every key is required and strictly boolean. */
final class PlanEntitlements
{
    public const array KEYS = ['multi_currency', 'independent_approvals', 'fixed_assets', 'budgets', 'api_access', 'consolidation', 'audit_chain_export', 'custom_roles'];

    /** @return array<string, bool> */
    public static function validate(mixed $entitlements): array
    {
        if (! is_array($entitlements) || array_diff(array_keys($entitlements), self::KEYS) !== [] || array_diff(self::KEYS, array_keys($entitlements)) !== []) {
            throw DomainRuleViolation::single('plan_entitlements_invalid', 'Entitlements need exactly: '.implode(', ', self::KEYS).'.');
        }
        $clean = [];
        foreach (self::KEYS as $key) {
            if (! is_bool($entitlements[$key])) {
                throw DomainRuleViolation::single('plan_entitlements_invalid', "Entitlement {$key} must be true or false.");
            }
            $clean[$key] = $entitlements[$key];
        }

        return $clean;
    }
}
