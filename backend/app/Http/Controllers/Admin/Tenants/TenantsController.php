<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Actions\Admin\Tenants\ChangeLifecycleByAdmin;
use App\Domain\Platform\LifecycleState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAuditEventResource;
use App\Http\Resources\Admin\AdminTenantResource;
use App\Http\Support\RequestAdminActor;
use App\Models\Admin\AdminAuditEvent;
use App\Models\Platform\Tenant;
use App\Queries\Admin\Tenants\ListTenants;
use App\Queries\Admin\Tenants\ShowTenant;
use App\Queries\Admin\Tenants\TenantAdminAudit;
use App\Queries\Admin\Tenants\TenantCompanies;
use App\Queries\Admin\Tenants\TenantUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

/** The admin tenants list and tenant page (plan Task 7): validate → act or query → respond. */
final class TenantsController extends Controller
{
    public function index(Request $request, ListTenants $list): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LifecycleState::class)],
            'plan' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'q' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['created_at', 'name'])],
        ]);
        ['page' => $page, 'counts' => $counts] = $list($request, $filters);

        return response()->json([
            'data' => $page->items->map(function ($t) use ($counts): array {
                $tenant = $t instanceof Tenant ? $t : throw new LogicException;

                return AdminTenantResource::make($tenant, $counts[$tenant->id] ?? null);
            })->all(),
            'meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit],
        ]);
    }

    public function show(ShowTenant $show, string $tenant): JsonResponse
    {
        return response()->json(['data' => $show(self::uuid($tenant))]);
    }

    public function users(TenantUsers $users, string $tenant): JsonResponse
    {
        $id = self::uuid($tenant);
        ShowTenant::mustExist($id);

        return response()->json(['data' => $users($id)]);
    }

    public function companies(TenantCompanies $companies, string $tenant): JsonResponse
    {
        $id = self::uuid($tenant);
        ShowTenant::mustExist($id);

        return response()->json(['data' => $companies($id)]);
    }

    public function audit(Request $request, TenantAdminAudit $audit, string $tenant): JsonResponse
    {
        $id = self::uuid($tenant);
        ShowTenant::mustExist($id);
        $page = $audit($request, $id);

        return response()->json([
            'data' => $page->items->map(fn ($e): array => AdminAuditEventResource::make($e instanceof AdminAuditEvent ? $e : throw new LogicException))->all(),
            'meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit],
        ]);
    }

    public function lifecycle(Request $request, ChangeLifecycleByAdmin $change, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::enum(LifecycleState::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $id = self::uuid($tenant);

        $change->handle(RequestAdminActor::from($request), $id, LifecycleState::from($data['to']), $data['reason']);

        return response()->json(['data' => ShowTenant::row($id)]);
    }

    /** Route ids that are not uuids are unknown tenants, not a 500 from the uuid cast. */
    public static function uuid(string $id): string
    {
        return Str::isUuid($id) ? $id : throw ShowTenant::notFound();
    }
}
