<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\DB;

/** Staff notes on a tenant: pinned first, then newest first, with the author's name. */
final class TenantNotes
{
    /** @return list<array{id: string, body: string, pinned: bool, admin: array{id: string, name: string|null}, created_at: string}> */
    public function __invoke(string $tenantId, ?string $noteId = null): array
    {
        $query = DB::connection(AdminTransaction::CONNECTION)->table('tenant_notes as n')
            ->leftJoin('admin_users as a', 'a.id', '=', 'n.admin_id')
            ->where('n.tenant_id', $tenantId)
            ->orderByDesc('n.pinned')->orderByDesc('n.created_at')->orderByDesc('n.id')
            ->select(['n.id', 'n.body', 'n.pinned', 'n.admin_id', 'a.name as admin_name', 'n.created_at']);
        if ($noteId !== null) {
            $query->where('n.id', $noteId);
        }

        $out = [];
        foreach ($query->get() as $row) {
            /** @var array{id: string, body: string, pinned: bool, admin_id: string, admin_name: string|null, created_at: string} $r */
            $r = (array) $row;
            $out[] = [
                'id' => $r['id'], 'body' => $r['body'], 'pinned' => (bool) $r['pinned'],
                'admin' => ['id' => $r['admin_id'], 'name' => $r['admin_name']], 'created_at' => $r['created_at'],
            ];
        }

        return $out;
    }
}
