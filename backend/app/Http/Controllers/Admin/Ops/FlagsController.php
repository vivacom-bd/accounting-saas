<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Ops;

use App\Actions\Admin\Flags\SetFlagForTenant;
use App\Actions\Admin\Flags\SetFlagGlobally;
use App\Http\Controllers\Admin\Tenants\TenantsController;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Queries\Admin\Flags\ListFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Feature flags (plan Task 17): list for `ops.view`, writes for `flags.manage`. */
final class FlagsController extends Controller
{
    public function index(ListFlags $flags): JsonResponse
    {
        return response()->json(['data' => $flags()]);
    }

    public function setGlobally(Request $request, SetFlagGlobally $set, string $name): JsonResponse
    {
        $data = $request->validate(['active' => ['present', 'nullable', 'boolean']]);
        $active = $set->handle(RequestAdminActor::from($request), $name, $data['active'] === null ? null : (bool) $data['active']);

        return response()->json(['data' => ['name' => $name, 'active' => $active]]);
    }

    public function setForTenant(Request $request, SetFlagForTenant $set, string $tenant, string $name): JsonResponse
    {
        $data = $request->validate(['active' => ['present', 'nullable', 'boolean']]);
        $id = TenantsController::uuid($tenant);
        $active = $set->handle(RequestAdminActor::from($request), $id, $name, $data['active'] === null ? null : (bool) $data['active']);

        return response()->json(['data' => ['tenant_id' => $id, 'name' => $name, 'active' => $active]]);
    }
}
