<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Ops;

use App\Actions\Admin\Ops\SetMaintenance;
use App\Actions\Admin\Ops\SetRateLimitOverride;
use App\Http\Controllers\Admin\Tenants\TenantsController;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Queries\Admin\Ops\Health;
use App\Queries\Admin\Ops\MaintenanceSetting;
use App\Queries\Admin\Ops\TenantRateLimitSetting;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Platform operations (plan Task 17): health, maintenance mode and tenant rate-limit overrides. Gated per route by `admin.can`. */
final class OpsController extends Controller
{
    public function health(Health $health): JsonResponse
    {
        return response()->json(['data' => $health()]);
    }

    public function maintenance(MaintenanceSetting $setting): JsonResponse
    {
        return response()->json(['data' => $setting()]);
    }

    public function setMaintenance(Request $request, SetMaintenance $set): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
            'ends_at' => ['nullable', 'date', 'after:now'],
        ]);

        $state = $set->handle(
            RequestAdminActor::from($request), (bool) $data['enabled'], $data['message'] ?? null,
            isset($data['ends_at']) ? new DateTimeImmutable($data['ends_at']) : null,
        );

        return response()->json(['data' => $state]);
    }

    public function rateLimit(string $tenant, TenantRateLimitSetting $setting): JsonResponse
    {
        return response()->json(['data' => $setting(TenantsController::uuid($tenant))]);
    }

    public function setRateLimit(Request $request, SetRateLimitOverride $set, TenantRateLimitSetting $setting, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'per_minute' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $id = TenantsController::uuid($tenant);

        $set->handle(
            RequestAdminActor::from($request), $id, (int) $data['per_minute'], $data['reason'],
            isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null,
        );

        return response()->json(['data' => $setting($id)]);
    }

    public function clearRateLimit(Request $request, SetRateLimitOverride $set, string $tenant): Response
    {
        $set->clear(RequestAdminActor::from($request), TenantsController::uuid($tenant));

        return response()->noContent();
    }
}
