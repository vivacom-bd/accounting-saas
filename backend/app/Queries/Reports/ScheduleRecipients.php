<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Models\Platform\TenantUser;
use App\Models\Platform\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The people a scheduled report may be mailed to by name: active members of the organisation who can work in the company
 * (organisation owners and admins, and anyone holding a role in it). Anyone else is an outside address, typed in.
 */
final class ScheduleRecipients
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return list<array{id: string, name: string, email: string}> */
    public function __invoke(string $companyId): array
    {
        $tenantId = $this->tenant->require();
        $withRole = DB::table('model_has_roles')->where('tenant_id', $tenantId)->where('company_id', $companyId)
            ->where('model_type', (new User)->getMorphClass())->pluck('model_id')->map(fn (mixed $id): string => (string) $id)->all();

        $rows = TenantUser::query()->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', $tenantId)->where('tenant_users.status', 'active')
            ->where(fn ($q) => $q->whereIn('tenant_users.role', ['owner', 'admin'])->orWhereIn('tenant_users.user_id', $withRole))
            ->orderBy('users.name')->orderBy('users.id')->limit(500)
            ->get(['users.id', 'users.name', 'users.email']);

        return array_values($rows->map(fn (TenantUser $r): array => ['id' => (string) $r->getAttribute('id'), 'name' => (string) $r->getAttribute('name'), 'email' => (string) $r->getAttribute('email')])->all());
    }
}
