<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Tenants;

use App\Actions\Admin\Tenants\AddTenantNote;
use App\Actions\Admin\Tenants\PinTenantNote;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Queries\Admin\Tenants\TenantNotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Staff notes on the tenant page (`tenants.notes`). */
final class TenantNotesController extends Controller
{
    public function store(Request $request, AddTenantNote $add, TenantNotes $notes, string $tenant): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $id = TenantsController::uuid($tenant);

        $noteId = $add->handle(RequestAdminActor::from($request), $id, $data['body']);

        return response()->json(['data' => $notes($id, $noteId)[0]], 201);
    }

    public function pin(Request $request, PinTenantNote $pin, TenantNotes $notes, string $tenant, string $note): JsonResponse
    {
        $data = $request->validate(['pinned' => ['sometimes', 'boolean']]);
        $id = TenantsController::uuid($tenant);
        $noteId = Str::isUuid($note) ? $note : throw DomainRuleViolation::single('not_found', 'Note not found.');

        $pin->handle(RequestAdminActor::from($request), $id, $noteId, (bool) ($data['pinned'] ?? true));

        return response()->json(['data' => $notes($id, $noteId)[0]]);
    }
}
