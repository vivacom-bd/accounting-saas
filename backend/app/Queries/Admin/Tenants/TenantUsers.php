<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\DB;

/** `GET /api/admin/tenants/{id}/users`: the tenant's members through sysops.admin_tenant_users (no secrets). */
final class TenantUsers
{
    /** @return list<array{id: string, name: string, email: string, org_role: string, status: string, two_factor: bool, last_login_at: string|null}> */
    public function __invoke(string $tenantId): array
    {
        $rows = DB::connection(AdminTransaction::CONNECTION)->select(
            'SELECT user_id, name, email, org_role, status, two_factor, last_login_at FROM sysops.admin_tenant_users(?)',
            [$tenantId],
        );

        return array_values(array_map(fn (object $row): array => self::row((array) $row), $rows));
    }

    /** @return array{id: string, name: string, email: string, org_role: string, status: string, two_factor: bool, last_login_at: string|null}|null */
    public function one(string $tenantId, string $userId): ?array
    {
        foreach ($this($tenantId) as $user) {
            if ($user['id'] === $userId) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $r
     * @return array{id: string, name: string, email: string, org_role: string, status: string, two_factor: bool, last_login_at: string|null}
     */
    private static function row(array $r): array
    {
        return [
            'id' => self::str($r['user_id']),
            'name' => self::str($r['name']),
            'email' => self::str($r['email']),
            'org_role' => self::str($r['org_role']),
            'status' => self::str($r['status']),
            'two_factor' => (bool) $r['two_factor'],
            'last_login_at' => $r['last_login_at'] === null ? null : self::str($r['last_login_at']),
        ];
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
