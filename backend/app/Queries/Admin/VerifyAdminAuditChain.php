<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Domain\Audit\Jcs;
use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the platform admin audit chain from the stored columns (plan T10): every link's prev_hash, its canonical
 * form, its hash, and finally the head row. Returns the id of the first broken event, or null when intact.
 */
final class VerifyAdminAuditChain
{
    /** @return array{events: int, brokenAtId: int|null, problem: string|null} */
    public function __invoke(): array
    {
        $db = DB::connection(AdminTransaction::CONNECTION);

        $prevHash = null;
        $lastId = null;
        $count = 0;

        $rows = $db->table('admin_audit_events')
            ->selectRaw(
                'id, admin_id, action, tenant_id, subject_type, subject_id, before::text AS before, after::text AS after, reason, host(ip) AS ip, '
                ."user_agent, request_id, to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS occurred_at, "
                .'canonical, prev_hash, hash'
            )
            ->lazyById(1000, 'id');

        foreach ($rows as $row) {
            $count++;
            if ($row->prev_hash !== $prevHash) {
                return $this->broken($count, (int) $row->id, 'prev_hash does not match the previous event');
            }
            $expected = Jcs::encode([
                'admin_id' => $row->admin_id,
                'action' => $row->action,
                'tenant_id' => $row->tenant_id,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'before' => self::decode($row->before),
                'after' => self::decode($row->after),
                'reason' => $row->reason,
                'ip' => $row->ip,
                'user_agent' => $row->user_agent,
                'request_id' => $row->request_id,
                'occurred_at' => $row->occurred_at,
                'prev_hash' => $row->prev_hash,
            ]);
            if ($row->canonical !== $expected) {
                return $this->broken($count, (int) $row->id, 'stored columns differ from the canonical form');
            }
            if ($row->hash !== hash('sha256', ($prevHash ?? '').$row->canonical)) {
                return $this->broken($count, (int) $row->id, 'hash does not match the canonical form');
            }
            $prevHash = $row->hash;
            $lastId = (int) $row->id;
        }

        // No head row means no event was ever written through the writer (a truncated test database).
        $head = $db->selectOne('SELECT last_id, last_hash FROM admin_audit_head WHERE id = 1');
        $headId = $head === null || $head->last_id === null ? null : (int) $head->last_id;
        $headHash = $head === null ? null : $head->last_hash;
        if ($headId !== $lastId || $headHash !== $prevHash) {
            return $this->broken($count, $headId ?? $lastId ?? 0, 'the chain head does not match the newest event (events removed or appended outside the writer)');
        }

        return ['events' => $count, 'brokenAtId' => null, 'problem' => null];
    }

    /** @return array{events: int, brokenAtId: int|null, problem: string|null} */
    private function broken(int $count, int $id, string $problem): array
    {
        return ['events' => $count, 'brokenAtId' => $id, 'problem' => $problem];
    }

    private static function decode(?string $json): mixed
    {
        return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
