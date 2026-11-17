<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Contracts\Clock;
use App\Domain\Audit\Jcs;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Appends to the platform admin audit chain (plan T10). Same rule as the tenant chain (13 §14):
 * canonical = JCS(fields), hash = sha256(prev_hash ‖ canonical); the single `admin_audit_head` row is locked FOR UPDATE.
 */
final class AdminAuditWriter
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * Must be called inside an AdminTransaction. Returns the new event id.
     *
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    public function record(
        AdminActor $actor,
        string $action,
        ?string $tenantId,
        string $subjectType,
        ?string $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): int {
        return $this->append(
            $actor->id, $action, $tenantId, $subjectType, $subjectId, $before, $after, $reason,
            $actor->ip, $actor->userAgent, $actor->requestId,
        );
    }

    /**
     * An event with no signed-in staff member behind it (`admin_id` null): failed or blocked sign-ins. Same chain,
     * same transaction rule.
     *
     * @param  array<array-key, mixed>|null  $after
     */
    public function recordAnonymous(
        AdminRequestContext $context,
        string $action,
        string $subjectType,
        ?string $subjectId,
        ?array $after = null,
        ?string $reason = null,
    ): int {
        return $this->append(
            null, $action, null, $subjectType, $subjectId, null, $after, $reason,
            $context->ip, $context->userAgent, $context->requestId,
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    private function append(
        ?string $adminId,
        string $action,
        ?string $tenantId,
        string $subjectType,
        ?string $subjectId,
        ?array $before,
        ?array $after,
        ?string $reason,
        ?string $ip,
        ?string $userAgent,
        ?string $requestId,
    ): int {
        $db = DB::connection(AdminTransaction::CONNECTION);
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Admin audit events must be recorded inside an AdminTransaction.');
        }

        // The head row is seeded by the migration; the insert covers a database whose tables were truncated.
        $db->statement('INSERT INTO admin_audit_head (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
        /** @var object{last_id: int|null, last_hash: string|null} $head */
        $head = $db->selectOne('SELECT last_id, last_hash FROM admin_audit_head WHERE id = 1 FOR UPDATE');

        $prevHash = $head->last_hash;
        $occurredAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

        $fields = [
            'admin_id' => $adminId,
            'action' => $action,
            'tenant_id' => $tenantId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'request_id' => $requestId,
            'occurred_at' => $occurredAt,
            'prev_hash' => $prevHash,
        ];
        $canonical = Jcs::encode($fields);
        $hash = hash('sha256', ($prevHash ?? '').$canonical);

        /** @var object{id: int} $row */
        $row = $db->selectOne(
            'INSERT INTO admin_audit_events (admin_id, action, tenant_id, subject_type, subject_id, before, after, reason, ip, '
            .'user_agent, request_id, occurred_at, canonical, prev_hash, hash) '
            .'VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $adminId, $action, $tenantId, $subjectType, $subjectId,
                $before === null ? null : Jcs::encode($before), $after === null ? null : Jcs::encode($after),
                $reason, $ip, $userAgent, $requestId, $occurredAt, $canonical, $prevHash, $hash,
            ],
        );

        $db->update('UPDATE admin_audit_head SET last_id = ?, last_hash = ? WHERE id = 1', [$row->id, $hash]);

        return $row->id;
    }
}
