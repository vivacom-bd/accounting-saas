<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Contracts\Clock;
use App\Support\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The per-minute override an admin set for the current tenant (plan Task 17), read through sysops.tenant_rate_limit()
 * (bound to app.tenant_id) and cached for 60 seconds, expiry checked again on every use. Any failure means "no
 * override": the limiter falls back to its default.
 */
final class TenantRateLimits
{
    public const int TTL_SECONDS = 60;

    public function __construct(private readonly TenantContext $context, private readonly Clock $clock) {}

    public static function cacheKey(string $tenantId): string
    {
        return "tenant-rate-limit:{$tenantId}";
    }

    public function forCurrentTenant(): ?int
    {
        $tenantId = $this->context->id();
        if ($tenantId === null) {
            return null;
        }

        try {
            /** @var array{per_minute: int|null, expires_at: string|null} $entry */
            $entry = Cache::remember(self::cacheKey($tenantId), self::TTL_SECONDS, function (): array {
                $row = DB::connection('pgsql')->selectOne('SELECT per_minute, expires_at FROM sysops.tenant_rate_limit()');

                return [
                    'per_minute' => $row === null ? null : (int) $row->per_minute,
                    'expires_at' => $row === null || $row->expires_at === null ? null : (new DateTimeImmutable($row->expires_at))->format(\DATE_ATOM),
                ];
            });
        } catch (Throwable) {
            return null;
        }

        if ($entry['per_minute'] === null) {
            return null;
        }
        if ($entry['expires_at'] !== null && new DateTimeImmutable($entry['expires_at']) <= $this->clock->now()) {
            return null;
        }

        return $entry['per_minute'];
    }

    public function forget(string $tenantId): void
    {
        Cache::forget(self::cacheKey($tenantId));
    }
}
