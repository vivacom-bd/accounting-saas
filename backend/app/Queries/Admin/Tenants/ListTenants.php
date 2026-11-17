<?php

declare(strict_types=1);

namespace App\Queries\Admin\Tenants;

use App\Http\Support\CursorPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * `GET /api/admin/tenants`: keyset-paged directory. Filters: status (lifecycle), plan (code), country (ISO-2),
 * q (name, slug or an active owner's email, case-insensitive substring). Sort: created_at (newest first, default)
 * or name (A–Z).
 */
final class ListTenants
{
    /**
     * @param  array{status?: string|null, plan?: string|null, country?: string|null, q?: string|null, sort?: string|null}  $filters
     * @return array{page: CursorPage, counts: array<string, array{companies: int, users: int, last_activity_at: string|null}>}
     */
    public function __invoke(Request $request, array $filters): array
    {
        $query = TenantDirectory::query();

        if (($filters['status'] ?? null) !== null) {
            $query->where('tenants.lifecycle', $filters['status']);
        }
        if (($filters['plan'] ?? null) !== null) {
            $query->whereRaw('lower(plan.plan_code) = lower(?)', [$filters['plan']]);
        }
        if (($filters['country'] ?? null) !== null) {
            $query->where('tenants.country', strtoupper($filters['country']));
        }
        $q = $filters['q'] ?? null;
        if ($q !== null && $q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function (Builder $w) use ($like): void {
                $w->where('tenants.name', 'ilike', $like)
                    ->orWhere('tenants.slug', 'ilike', $like)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('tenant_users as qtu')->join('users as qu', 'qu.id', '=', 'qtu.user_id')
                        ->whereColumn('qtu.tenant_id', 'tenants.id')->where('qtu.role', 'owner')->where('qtu.status', 'active')
                        ->where('qu.email', 'ilike', $like));
            });
        }

        $page = ($filters['sort'] ?? 'created_at') === 'name'
            ? CursorPage::fetch($query, $request, 'name', false, 'text')
            : CursorPage::fetch($query, $request, 'created_at', true, 'timestamp');

        /** @var list<string> $ids */
        $ids = $page->items->map(fn ($t): string => (string) $t->getKey())->values()->all();

        return ['page' => $page, 'counts' => TenantDirectory::counts($ids)];
    }
}
