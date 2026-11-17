<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Models\Platform\Tenant;
use App\Support\Admin\AdminTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The tenant directory as staff see it (plan Task 7), on the platform connection: each tenant with its owner (the
 * first active org owner by joined_at) and its plan (from `settings.plan_version_id` until subscriptions land in
 * Task 11). Figures from inside the tenant come from sysops.admin_tenant_counts, never from tenant tables.
 */
final class TenantDirectory
{
    /** @return Builder<Tenant> tenants.* plus owner_name, owner_email, plan_code, plan_name, plan_limits */
    public static function query(): Builder
    {
        $db = DB::connection(AdminTransaction::CONNECTION);
        $owner = $db->table('tenant_users as tu')->join('users as u', 'u.id', '=', 'tu.user_id')
            ->whereColumn('tu.tenant_id', 'tenants.id')->where('tu.role', 'owner')->where('tu.status', 'active')
            ->orderByRaw('tu.joined_at NULLS LAST')->orderBy('tu.user_id')->limit(1)
            ->select(['u.name as owner_name', 'u.email as owner_email']);
        $plan = $db->table('plan_versions as pv')->join('plans as p', 'p.id', '=', 'pv.plan_id')
            ->whereRaw("pv.id::text = tenants.settings->>'plan_version_id'")
            ->select(['p.code as plan_code', 'p.name as plan_name', 'pv.limits as plan_limits']);

        /** @var Builder<Tenant> $query */
        $query = Tenant::on(AdminTransaction::CONNECTION)
            ->select(['tenants.*', 'owner.owner_name', 'owner.owner_email', 'plan.plan_code', 'plan.plan_name', 'plan.plan_limits'])
            ->leftJoinLateral($owner, 'owner')
            ->leftJoinLateral($plan, 'plan');

        return $query;
    }

    public static function find(string $id): ?Tenant
    {
        return self::query()->where('tenants.id', $id)->first();
    }

    /**
     * @param  list<string>  $ids  at most 500 (the sysops function's bound)
     * @return array<string, array{companies: int, users: int, last_activity_at: string|null}>
     */
    public static function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::connection(AdminTransaction::CONNECTION)->select(
            'SELECT tenant_id, companies, users, last_activity_at FROM sysops.admin_tenant_counts(?::uuid[])',
            ['{'.implode(',', $ids).'}'],
        );

        $out = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $out[(string) $r['tenant_id']] = [
                'companies' => (int) $r['companies'],
                'users' => (int) $r['users'],
                'last_activity_at' => $r['last_activity_at'] === null ? null : (string) $r['last_activity_at'],
            ];
        }

        return $out;
    }
}
