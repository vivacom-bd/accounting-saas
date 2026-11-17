<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Support\Admin\AdminTransaction;
use Illuminate\Support\Facades\DB;

/** The tenant's lifecycle history, oldest first (Task 5's tenant_lifecycle_events). */
final class TenantTimeline
{
    /** @return list<array{id: string, from: string, to: string, reason: string, actor_type: string, actor_id: string|null, occurred_at: string}> */
    public function __invoke(string $tenantId): array
    {
        $rows = DB::connection(AdminTransaction::CONNECTION)->table('tenant_lifecycle_events')
            ->where('tenant_id', $tenantId)->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'from_status', 'to_status', 'reason', 'actor_type', 'actor_id', 'occurred_at']);

        $out = [];
        foreach ($rows as $row) {
            /** @var array{id: string, from_status: string, to_status: string, reason: string, actor_type: string, actor_id: string|null, occurred_at: string} $r */
            $r = (array) $row;
            $out[] = [
                'id' => $r['id'], 'from' => $r['from_status'], 'to' => $r['to_status'], 'reason' => $r['reason'],
                'actor_type' => $r['actor_type'], 'actor_id' => $r['actor_id'], 'occurred_at' => $r['occurred_at'],
            ];
        }

        return $out;
    }
}
