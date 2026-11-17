<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Domain\Billing\PlanEntitlements;
use App\Domain\Billing\PlanLimits;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;

/**
 * Sets (or replaces) one entitlement override for a tenant (plan Task 10): a limit key takes a whole number or null for
 * unlimited, a feature key takes true or false. The reason is required and the optional expiry makes it temporary; the
 * plan applies again afterwards. Recorded in the admin audit chain in the same platform transaction.
 */
final class SetEntitlementOverride
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    /** @return array{key: string, value: mixed, reason: string, set_by: string, expires_at: string|null} */
    public function __invoke(AdminActor $actor, string $tenantId, string $key, mixed $value, string $reason, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): array
    {
        self::validate($key, $value);
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw DomainRuleViolation::single('override_expiry_invalid', 'The expiry must be in the future.');
        }

        return AdminTransaction::run(function () use ($actor, $tenantId, $key, $value, $reason, $expiresAt, $now): array {
            $db = AdminTransaction::connection();
            if (! $db->table('tenants')->where('id', $tenantId)->exists()) {
                throw DomainRuleViolation::single('not_found', 'There is no such organisation.');
            }
            $before = $db->table('tenant_entitlement_overrides')->where('tenant_id', $tenantId)->where('key', $key)->first();
            $row = [
                'value' => json_encode($value, JSON_THROW_ON_ERROR), 'reason' => $reason, 'set_by' => $actor->id,
                'expires_at' => $expiresAt?->format('Y-m-d\TH:i:sP'), 'updated_at' => $now->format('Y-m-d\TH:i:sP'),
            ];
            $db->table('tenant_entitlement_overrides')->updateOrInsert(['tenant_id' => $tenantId, 'key' => $key], $row + ['created_at' => $now->format('Y-m-d\TH:i:sP')]);

            $after = ['key' => $key, 'value' => $value, 'reason' => $reason, 'set_by' => $actor->id, 'expires_at' => $expiresAt?->format(DATE_ATOM)];
            $this->audit->record(
                $actor, 'entitlement_override.set', $tenantId, 'tenant_entitlement_override', $tenantId.':'.$key,
                before: $before === null ? null : ['value' => json_decode((string) $before->value, true), 'reason' => $before->reason, 'expires_at' => $before->expires_at],
                after: $after, reason: $reason,
            );

            return $after;
        });
    }

    /** Limit keys: a non-negative whole number or null (unlimited). Feature keys: true or false. */
    public static function validate(string $key, mixed $value): void
    {
        if (in_array($key, PlanLimits::KEYS, true)) {
            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw DomainRuleViolation::single('override_value_invalid', "Limit {$key} takes a whole number, zero or more, or null for unlimited.");
            }

            return;
        }
        if (in_array($key, PlanEntitlements::KEYS, true)) {
            if (! is_bool($value)) {
                throw DomainRuleViolation::single('override_value_invalid', "Feature {$key} takes true or false.");
            }

            return;
        }
        throw DomainRuleViolation::single('override_key_unknown', "There is no limit or feature named {$key}.");
    }
}
