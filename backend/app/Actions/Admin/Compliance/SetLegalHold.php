<?php

declare(strict_types=1);

namespace App\Actions\Admin\Compliance;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Legal hold on a tenant (plan Task 18). Setting is direct and flips a live scheduled deletion to blocked; releasing
 * is the executor path of a legal_hold_release approval and flips blocked back to scheduled, keeping the date unless
 * it has passed (then today + 7 days) or retention (archive_until) reaches further. Both are idempotent.
 */
final class SetLegalHold
{
    public const int GRACE_DAYS_AFTER_RELEASE = 7;

    public function __construct(private readonly Clock $clock, private readonly AdminAuditWriter $audit) {}

    public function set(AdminActor $actor, string $tenantId, string $reason): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $reason): void {
            $tenant = $this->lock($tenantId);
            if ($tenant['legal_hold']) {
                return;
            }
            $db->table('tenants')->where('id', $tenantId)->update(['legal_hold' => true]);
            $blocked = $db->table('tenant_deletions')->where('tenant_id', $tenantId)->where('status', 'scheduled')
                ->update(['status' => 'blocked', 'updated_at' => $this->clock->now()]);

            $this->audit->record($actor, 'tenant.legal_hold_set', $tenantId, 'tenant', $tenantId,
                ['legal_hold' => false], ['legal_hold' => true, 'deletions_blocked' => $blocked], $reason);
        });
    }

    /** Runs inside the approval's admin transaction (nested here as a savepoint). */
    public function release(AdminActor $actor, string $tenantId, string $reason): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $reason): void {
            $tenant = $this->lock($tenantId);
            $deletion = $db->table('tenant_deletions')->where('tenant_id', $tenantId)->where('status', 'blocked')->lockForUpdate()->first(['id', 'scheduled_for']);
            if (! $tenant['legal_hold'] && $deletion === null) {
                return; // already released: nothing to redo
            }

            $db->table('tenants')->where('id', $tenantId)->update(['legal_hold' => false]);
            $after = ['legal_hold' => false];

            if ($deletion !== null) {
                $today = $this->clock->now()->format('Y-m-d');
                $scheduledFor = (string) $deletion->scheduled_for;
                $archive = $tenant['archive_until'];
                $rescheduled = false;
                if ($scheduledFor < $today) {
                    $scheduledFor = $this->clock->now()->modify('+'.self::GRACE_DAYS_AFTER_RELEASE.' days')->format('Y-m-d');
                    $rescheduled = true;
                }
                if ($archive !== null && $archive > $scheduledFor) {
                    $scheduledFor = $archive;
                }
                $db->table('tenant_deletions')->where('id', $deletion->id)
                    ->update(['status' => 'scheduled', 'scheduled_for' => $scheduledFor, 'updated_at' => $this->clock->now()]);
                $after += ['deletion' => 'scheduled', 'scheduled_for' => $scheduledFor, 'rescheduled' => $rescheduled];
                if ($rescheduled) {
                    $after['note'] = 'The scheduled date had already passed; moved to today + '.self::GRACE_DAYS_AFTER_RELEASE.' days.';
                }
            }

            $this->audit->record($actor, 'tenant.legal_hold_released', $tenantId, 'tenant', $tenantId, ['legal_hold' => true], $after, $reason);
        });
    }

    /** @return array{legal_hold: bool, archive_until: string|null} */
    private function lock(string $tenantId): array
    {
        $row = AdminTransaction::connection()->selectOne('SELECT legal_hold, archive_until FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
        if ($row === null) {
            throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        }
        $r = (array) $row;

        return ['legal_hold' => (bool) $r['legal_hold'], 'archive_until' => is_string($r['archive_until']) ? $r['archive_until'] : null];
    }
}
