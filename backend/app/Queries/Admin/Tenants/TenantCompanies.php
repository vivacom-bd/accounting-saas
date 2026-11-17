<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\DB;

/** `GET /api/admin/tenants/{id}/companies`: the tenant's companies through sysops.admin_tenant_companies. */
final class TenantCompanies
{
    /** @return list<array<string, mixed>> */
    public function __invoke(string $tenantId): array
    {
        $rows = DB::connection(AdminTransaction::CONNECTION)->select(
            'SELECT id, name, status, base_currency, country, fiscal_year_start, open_periods, closed_periods, posted_journals '
            .'FROM sysops.admin_tenant_companies(?)',
            [$tenantId],
        );

        return array_values(array_map(function (object $row): array {
            $r = (array) $row;

            return [
                'id' => $r['id'],
                'name' => $r['name'],
                'status' => $r['status'],
                'base_currency' => $r['base_currency'],
                'country' => $r['country'],
                'fiscal_year_start' => $r['fiscal_year_start'],
                'open_periods' => (int) $r['open_periods'],
                'closed_periods' => (int) $r['closed_periods'],
                'posted_journals' => (int) $r['posted_journals'],
            ];
        }, $rows));
    }
}
