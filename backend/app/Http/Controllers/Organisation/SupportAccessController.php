<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organisation;

use App\Actions\Organisation\GrantSupportAccess;
use App\Actions\Organisation\RevokeSupportAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportAccessResource;
use App\Http\Support\RequestActor;
use App\Models\Platform\Tenant;
use App\Queries\Organisation\SupportAccessStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/**
 * Support access (plan Task 14, G4): organisation owners and admins (people only) let platform support impersonate
 * members for 24, 72 or 168 hours, and can close it again at once, which ends any impersonation in progress.
 */
final class SupportAccessController extends Controller
{
    #[Authorize('manageSupportAccess', Tenant::class)]
    public function show(SupportAccessStatus $status): SupportAccessResource
    {
        return SupportAccessResource::of($status->open());
    }

    #[Authorize('manageSupportAccess', Tenant::class)]
    public function store(Request $request, GrantSupportAccess $action): JsonResponse
    {
        $hours = (int) $request->validate(['hours' => ['required', 'integer', 'in:24,72,168']])['hours'];

        return SupportAccessResource::of($action->handle(RequestActor::user($request), $hours))->response()->setStatusCode(201);
    }

    #[Authorize('manageSupportAccess', Tenant::class)]
    public function revoke(Request $request, RevokeSupportAccess $action, SupportAccessStatus $status): SupportAccessResource
    {
        $action->handle(RequestActor::user($request));

        return SupportAccessResource::of($status->open());
    }
}
