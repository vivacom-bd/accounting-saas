<?php

declare(strict_types=1);

namespace App\Actions\Admin\Ops;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use App\Support\Ops\TenantRateLimits;
use DateTimeImmutable;
use DateTimeZone;

/** Sets or clears a tenant's API rate-limit override (plan Task 17, 10 §3.9). One row per tenant; replacing it is an edit. */
final class SetRateLimitOverride
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AdminAuditWriter $audit,
        private readonly TenantRateLimits $limits,
    ) {}

    /** @return array{tenant_id: string, per_minute: int, reason: string, expires_at: string|null} */
    public function handle(AdminActor $actor, string $tenantId, int $perMinute, string $reason, ?DateTimeImmutable $expiresAt): array
    {
        $db = AdminTransaction::connection();
        $utc = new DateTimeZone('UTC');
        $after = [
            'tenant_id' => $tenantId, 'per_minute' => $perMinute, 'reason' => $reason,
            'expires_at' => $expiresAt?->setTimezone($utc)->format(\DATE_ATOM),
        ];

        AdminTransaction::run(function () use ($db, $actor, $tenantId, $after, $expiresAt, $utc): void {
            $this->requireTenant($tenantId);
            $existing = $db->table('tenant_rate_limits')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            $now = $this->clock->now()->setTimezone($utc)->format('Y-m-d H:i:s.uP');
            $fields = [
                'per_minute' => $after['per_minute'], 'reason' => $after['reason'], 'set_by' => $actor->id,
                'expires_at' => $expiresAt?->setTimezone($utc)->format('Y-m-d H:i:s.uP'), 'updated_at' => $now,
            ];

            if ($existing === null) {
                $db->table('tenant_rate_limits')->insert(['tenant_id' => $tenantId, 'created_at' => $now, ...$fields]);
            } else {
                $db->table('tenant_rate_limits')->where('tenant_id', $tenantId)->update($fields);
            }
            $this->audit->record($actor, 'ops.rate_limit_set', $tenantId, 'tenant_rate_limit', $tenantId, $existing === null ? null : $this->view($existing), $after, $after['reason']);
        });
        $this->limits->forget($tenantId);

        return $after;
    }

    public function clear(AdminActor $actor, string $tenantId): void
    {
        $db = AdminTransaction::connection();

        AdminTransaction::run(function () use ($db, $actor, $tenantId): void {
            $this->requireTenant($tenantId);
            $existing = $db->table('tenant_rate_limits')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($existing === null) {
                return;
            }
            $db->table('tenant_rate_limits')->where('tenant_id', $tenantId)->delete();
            $this->audit->record($actor, 'ops.rate_limit_cleared', $tenantId, 'tenant_rate_limit', $tenantId, $this->view($existing), null);
        });
        $this->limits->forget($tenantId);
    }

    private function requireTenant(string $tenantId): void
    {
        if (! AdminTransaction::connection()->table('tenants')->where('id', $tenantId)->exists()) {
            throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        }
    }

    /** @return array{tenant_id: string, per_minute: int, reason: string, expires_at: string|null} */
    private function view(object $stored): array
    {
        $row = (array) $stored;

        return [
            'tenant_id' => (string) $row['tenant_id'], 'per_minute' => (int) $row['per_minute'], 'reason' => (string) $row['reason'],
            'expires_at' => $row['expires_at'] === null ? null : (new DateTimeImmutable((string) $row['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }
}
