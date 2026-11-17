<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Contracts\Clock;
use App\Support\Audit\DetachedAudit;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The tenant app's only way to its impersonation sessions (a platform table it cannot read): the sysops functions in
 * database/sql/sysops_impersonation.sql. They run on the detached `pgsql_audit` connection (same app role), which a
 * read-only impersonation never makes read-only, so ending a session works in read mode too. Times come from the
 * application clock.
 */
final class ImpersonationStore
{
    /** Session key holding {id, tenant_id} of the impersonation this tenant session belongs to. */
    public const string SESSION_KEY = 'impersonation';

    public function __construct(private readonly DatabaseManager $db, private readonly Clock $clock) {}

    /** Spends a ticket, once; null when it is unknown, used, expired, or its basis is gone. */
    public function exchange(string $ticket): ?Impersonation
    {
        if ($ticket === '') {
            return null;
        }
        $row = $this->connection()->selectOne(
            'SELECT id, tenant_id, user_id, admin_id, admin_name, mode, ends_at FROM sysops.impersonation_by_ticket(?, ?)',
            [hash('sha256', $ticket), $this->now()],
        );

        return $row === null ? null : $this->impersonation((array) $row);
    }

    /** The session of this tenant with its `live` flag (ended, past its end, grant or member gone = not live); null if unknown. */
    public function state(string $id, string $tenantId): ?Impersonation
    {
        $row = $this->connection()->selectOne(
            'SELECT user_id, admin_id, admin_name, mode, ends_at, live FROM sysops.impersonation_state(?, ?, ?)',
            [$id, $tenantId, $this->now()],
        );

        return $row === null ? null : $this->impersonation(['id' => $id, 'tenant_id' => $tenantId, ...(array) $row]);
    }

    /** True when the session was still open. */
    public function end(string $id, string $tenantId): bool
    {
        $row = $this->connection()->selectOne('SELECT sysops.end_impersonation(?, ?, ?) AS ended', [$id, $tenantId, $this->now()]);

        return ($row->ended ?? false) === true;
    }

    /**
     * Ends every open session of the tenant in context (support access revoked). Runs on the default tenant connection,
     * so inside the caller's UnitOfWork it commits or rolls back with the revocation. Returns how many were live.
     */
    public function endAllInTenant(): int
    {
        $row = $this->db->connection()->selectOne('SELECT sysops.end_tenant_impersonations(?) AS ended', [$this->now()]);

        return is_numeric($row->ended ?? null) ? (int) $row->ended : 0;
    }

    private function connection(): Connection
    {
        return $this->db->connection(DetachedAudit::CONNECTION);
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    /** @param array<array-key, mixed> $r */
    private function impersonation(array $r): Impersonation
    {
        return new Impersonation(
            self::str($r['id'] ?? null), self::str($r['tenant_id'] ?? null), self::str($r['user_id'] ?? null),
            self::str($r['admin_id'] ?? null), self::str($r['admin_name'] ?? null), self::str($r['mode'] ?? null),
            (new DateTimeImmutable(self::str($r['ends_at'] ?? null)))->setTimezone(new DateTimeZone('UTC')),
            ($r['live'] ?? true) === true,
        );
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
