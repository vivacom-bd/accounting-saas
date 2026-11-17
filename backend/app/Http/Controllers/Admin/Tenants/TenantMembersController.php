<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Actions\Admin\Tenants\DisableTenantUser;
use App\Actions\Admin\Tenants\ResetUserTwoFactor;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Queries\Admin\Tenants\TenantUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Support actions on one member of a tenant: reset their 2FA, disable their access. */
final class TenantMembersController extends Controller
{
    public function resetTwoFactor(Request $request, ResetUserTwoFactor $reset, TenantUsers $users, string $tenant, string $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        [$tenantId, $userId] = self::ids($tenant, $user);

        $reset->handle(RequestAdminActor::from($request), $tenantId, $userId, $data['reason'] ?? null);

        return response()->json(['data' => $users->one($tenantId, $userId)]);
    }

    public function disable(Request $request, DisableTenantUser $disable, TenantUsers $users, string $tenant, string $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        [$tenantId, $userId] = self::ids($tenant, $user);

        $disable->handle(RequestAdminActor::from($request), $tenantId, $userId, $data['reason']);

        return response()->json(['data' => $users->one($tenantId, $userId)]);
    }

    /** @return array{string, string} */
    private static function ids(string $tenant, string $user): array
    {
        $tenantId = TenantsController::uuid($tenant);
        $userId = Str::isUuid($user) ? $user : throw DomainRuleViolation::single('not_found', 'This person is not in the organisation.');

        return [$tenantId, $userId];
    }
}
