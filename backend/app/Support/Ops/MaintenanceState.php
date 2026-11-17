<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Platform maintenance mode as the tenant app sees it (plan T8): the `maintenance` row of platform_settings, read
 * through sysops.platform_maintenance() (the app role holds no grant on the table) and cached for 30 seconds. A read
 * that fails counts as "not in maintenance": an outage of this check must not take the whole API down.
 */
final class MaintenanceState
{
    public const string CACHE_KEY = 'platform:maintenance';

    public const int TTL_SECONDS = 30;

    /** @return array{enabled: bool, message: string|null, ends_at: string|null} */
    public function current(): array
    {
        try {
            /** @var array{enabled: bool, message: string|null, ends_at: string|null} */
            return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function (): array {
                $row = DB::connection('pgsql')->selectOne('SELECT enabled, message, ends_at FROM sysops.platform_maintenance()');

                return [
                    'enabled' => (bool) ($row->enabled ?? false),
                    'message' => $row->message ?? null,
                    'ends_at' => isset($row->ends_at) ? (new \DateTimeImmutable($row->ends_at))->format(\DATE_ATOM) : null,
                ];
            });
        } catch (Throwable) {
            return ['enabled' => false, 'message' => null, 'ends_at' => null];
        }
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
