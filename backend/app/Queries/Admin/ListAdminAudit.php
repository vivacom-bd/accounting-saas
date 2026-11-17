<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Http\Support\CursorPage;
use App\Models\Admin\AdminAuditEvent;
use Illuminate\Http\Request;

/** The admin audit log, newest first, keyset-paged on the bigint id. Filters: admin, tenant, action, from, to (days). */
final class ListAdminAudit
{
    /** @param  array{admin?: string|null, tenant?: string|null, action?: string|null, from?: string|null, to?: string|null}  $filters */
    public function __invoke(Request $request, array $filters): CursorPage
    {
        $query = AdminAuditEvent::query()->selectRaw(
            'id, admin_id, action, tenant_id, subject_type, subject_id, before, after, reason, host(ip) AS ip, user_agent, request_id, occurred_at'
        );

        if (($filters['admin'] ?? null) !== null) {
            $query->where('admin_id', $filters['admin']);
        }
        if (($filters['tenant'] ?? null) !== null) {
            $query->where('tenant_id', $filters['tenant']);
        }
        if (($filters['action'] ?? null) !== null) {
            $query->where('action', $filters['action']);
        }
        if (($filters['from'] ?? null) !== null) {
            $query->whereRaw("occurred_at >= (?::date)::timestamp AT TIME ZONE 'UTC'", [$filters['from']]);
        }
        if (($filters['to'] ?? null) !== null) {
            $query->whereRaw("occurred_at < ((?::date) + 1)::timestamp AT TIME ZONE 'UTC'", [$filters['to']]);
        }

        return CursorPage::fetch($query, $request, 'id', true, 'int');
    }
}
