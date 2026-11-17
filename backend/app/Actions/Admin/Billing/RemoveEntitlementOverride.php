<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Removes an entitlement override: the tenant's plan applies again at once (plan Task 10). */
final class RemoveEntitlementOverride
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function __invoke(AdminActor $actor, string $tenantId, string $key): void
    {
        AdminTransaction::run(function () use ($actor, $tenantId, $key): void {
            $table = AdminTransaction::connection()->table('tenant_entitlement_overrides')->where('tenant_id', $tenantId)->where('key', $key);
            $before = (clone $table)->lockForUpdate()->first();
            if ($before === null) {
                throw DomainRuleViolation::single('not_found', 'There is no such override.');
            }
            $table->delete();
            $this->audit->record(
                $actor, 'entitlement_override.removed', $tenantId, 'tenant_entitlement_override', $tenantId.':'.$key,
                before: ['value' => json_decode((string) $before->value, true), 'reason' => $before->reason, 'expires_at' => $before->expires_at],
            );
        });
    }
}
