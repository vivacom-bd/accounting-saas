<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Extends how long a tenant's data is kept (13 §23 D8). A live deletion's scheduled_for becomes
 * max(scheduled_for, archive_until): retention can postpone a deletion, never bring it forward.
 */
final class SetArchiveUntil
{
    public function __construct(private readonly Clock $clock, private readonly AdminAuditWriter $audit) {}

    /** @param  string|null  $date  Y-m-d, or null to clear */
    public function handle(AdminActor $actor, string $tenantId, ?string $date, string $reason): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $date, $reason): void {
            $row = $db->selectOne('SELECT archive_until FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
            if ($row === null) {
                throw DomainRuleViolation::single('not_found', 'Organisation not found.');
            }
            $before = (array) $row;
            $db->table('tenants')->where('id', $tenantId)->update(['archive_until' => $date]);

            $moved = 0;
            if ($date !== null) {
                $moved = $db->update(
                    "UPDATE tenant_deletions SET scheduled_for = ?, updated_at = ? WHERE tenant_id = ? AND status IN ('scheduled','blocked') AND scheduled_for < ?",
                    [$date, $this->clock->now()->format('Y-m-d H:i:s.uP'), $tenantId, $date],
                );
            }

            $this->audit->record($actor, 'tenant.archive_until_set', $tenantId, 'tenant', $tenantId,
                ['archive_until' => $before['archive_until']], ['archive_until' => $date, 'deletions_postponed' => $moved], $reason);
        });
    }
}
