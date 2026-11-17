<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Actions\Admin\Tenants\ResendOwnerInvitation;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Resend the owner invitation of a tenant that has no active owner yet (`tenants.provision`). */
final class ResendOwnerInvitationController extends Controller
{
    public function __invoke(Request $request, ResendOwnerInvitation $resend, string $tenant): JsonResponse
    {
        $sent = $resend->handle(RequestAdminActor::from($request), TenantsController::uuid($tenant));

        return response()->json(['data' => ['owner_invitation' => ['email' => $sent['email'], 'expires_at' => $sent['expires_at']->format(DATE_ATOM)]]]);
    }
}
