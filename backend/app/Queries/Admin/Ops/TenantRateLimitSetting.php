<?php

declare(strict_types=1);

namespace App\Queries\Admin\Ops;

use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;

/** A tenant's stored rate-limit override, expired or not (the admin sees what is on file). */
final class TenantRateLimitSetting
{
    /** @return array{tenant_id: string, per_minute: int, reason: string, expires_at: string|null}|null */
    public function __invoke(string $tenantId): ?array
    {
        $row = AdminTransaction::connection()->table('tenant_rate_limits')->where('tenant_id', $tenantId)->first();
        if ($row === null) {
            return null;
        }

        return [
            'tenant_id' => $row->tenant_id, 'per_minute' => (int) $row->per_minute, 'reason' => $row->reason,
            'expires_at' => $row->expires_at === null ? null : (new DateTimeImmutable($row->expires_at))->setTimezone(new DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }
}
