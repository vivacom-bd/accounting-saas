<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Clock;
use App\Contracts\UsageMeter;
use App\Domain\Billing\PlanLimitReached;
use App\Domain\Billing\PlanLimits;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\DatabaseManager;

/**
 * What the current tenant's plan allows (plan Task 10, G8). Reads go through sysops.current_entitlements(), which applies
 * the plan version and the unexpired overrides. Hard limits (companies, users, api_clients) refuse the add; everything
 * else is only measured and never blocks, posting least of all. A tenant on no resolvable plan (no catalogue loaded)
 * has nothing to enforce. Not cached: a limit changed by staff applies on the next call.
 */
final class Entitlements
{
    /** @var list<string> */
    public const array HARD = ['companies', 'users', 'api_clients'];

    private const int MB = 1048576;

    public function __construct(private readonly DatabaseManager $db, private readonly UsageMeter $usage, private readonly TenantContext $tenant, private readonly Clock $clock) {}

    /** @return array{plan_code: string|null, limits: array<string, int|null>, entitlements: array<string, bool>} */
    public function snapshot(): array
    {
        $row = $this->db->connection()->selectOne('SELECT sysops.current_entitlements(?) AS e', [(string) config('billing.default_plan')]);
        /** @var array{plan_code?: string|null, limits?: array<string, int|null>, entitlements?: array<string, bool>} $data */
        $data = json_decode((string) ($row->e ?? '{}'), true, flags: JSON_THROW_ON_ERROR);

        return ['plan_code' => $data['plan_code'] ?? null, 'limits' => $data['limits'] ?? [], 'entitlements' => $data['entitlements'] ?? []];
    }

    /** The included quantity, or null for unlimited (also when the plan does not mention the key). */
    public function limit(string $key): ?int
    {
        $value = $this->snapshot()['limits'][$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function allows(string $feature): bool
    {
        $snapshot = $this->snapshot();

        return $snapshot['plan_code'] === null || ($snapshot['entitlements'][$feature] ?? false) === true;
    }

    /** @throws PlanLimitReached when one more would exceed a hard limit (companies, users, api_clients only) */
    public function assertCanAdd(string $key, int $current): void
    {
        if (! in_array($key, self::HARD, true)) {
            return;
        }
        $limit = $this->limit($key);
        if ($limit !== null && $current >= $limit) {
            throw new PlanLimitReached($key, $limit, $current);
        }
    }

    /**
     * Counts and checks under a transaction-scoped advisory lock, so two concurrent adds cannot both pass on the last
     * free seat. Call inside the Action's UnitOfWork, before the insert.
     *
     * @param  Closure(): int  $count
     */
    public function assertCanAddOne(string $tenantId, string $key, Closure $count): void
    {
        if (! in_array($key, self::HARD, true)) {
            return;
        }
        $this->db->connection()->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["plan_limit:{$tenantId}:{$key}"]);
        $this->assertCanAdd($key, $count());
    }

    /**
     * Every meter of the plan with the current figure, for the usage page.
     *
     * @return list<array{key: string, limit: int|null, used: int, ratio: float|null, level: 'ok'|'warn'|'over'}>
     */
    public function meters(): array
    {
        $snapshot = $this->snapshot();
        $usage = $this->usage->summary();
        $used = [
            'companies' => (int) $this->db->connection()->table('companies')->where('status', 'active')->count(),
            'users' => $this->userSeats(),
            'api_clients' => (int) $this->db->connection()->table('api_clients')->whereNull('revoked_at')->count(),
            'journal_lines_per_month' => $usage['journal_lines']['month'] ?? 0,
            'storage_mb' => intdiv(max(0, $usage['storage_bytes']['total'] ?? 0) + self::MB - 1, self::MB),
            'api_calls_per_day' => $usage['api_calls']['today'] ?? 0,
        ];

        $meters = [];
        foreach (PlanLimits::KEYS as $key) {
            $limit = $snapshot['limits'][$key] ?? null;
            $figure = (int) $used[$key];
            $ratio = $limit === null ? null : ($limit === 0 ? ($figure > 0 ? 1.0 : 0.0) : round($figure / $limit, 4));
            $meters[] = [
                'key' => $key, 'limit' => $limit === null ? null : (int) $limit, 'used' => $figure, 'ratio' => $ratio,
                'level' => $ratio === null || $ratio < 0.8 ? 'ok' : ($ratio < 1.0 ? 'warn' : 'over'),
            ];
        }

        return $meters;
    }

    /** Active members plus open invitations: the seats the users limit counts. */
    public function userSeats(?string $exceptInvitationId = null): int
    {
        $connection = $this->db->connection();
        // tenant_users is a platform table (no RLS), so the tenant is named explicitly.
        $members = (int) $connection->table('tenant_users')->where('tenant_id', $this->tenant->require())->where('status', 'active')->count();
        $invitations = $connection->table('invitations')->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', $this->clock->now()->format('Y-m-d H:i:sP'));
        if ($exceptInvitationId !== null) {
            $invitations->where('id', '!=', $exceptInvitationId);
        }

        return $members + (int) $invitations->count();
    }
}
