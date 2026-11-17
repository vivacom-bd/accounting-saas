<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Actions\Admin\Support\EndImpersonation;
use App\Actions\Admin\Support\RequestImpersonationWrite;
use App\Actions\Admin\Support\StartImpersonation;
use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Admin\ApprovalsController;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Admin\ImpersonationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Impersonation from the admin side (plan Task 14, G4/T6): start (returns the single-use ticket and the tenant SPA link),
 * ask for write mode (a two-person approval), end, and the log. The ticket is in the start response only.
 */
final class ImpersonationsController extends Controller
{
    public function index(Request $request, Clock $clock): JsonResponse
    {
        $data = $request->validate(['tenant' => ['nullable', 'uuid']]);
        $query = ImpersonationSession::query()
            ->join('admin_users', 'admin_users.id', '=', 'impersonation_sessions.admin_id')
            ->select('impersonation_sessions.*', 'admin_users.name as admin_name')
            ->orderByDesc('impersonation_sessions.created_at')->orderByDesc('impersonation_sessions.id');
        if (isset($data['tenant'])) {
            $query->where('impersonation_sessions.tenant_id', $data['tenant']);
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn (ImpersonationSession $s): array => self::resource($s, $clock))->all()]);
    }

    public function store(Request $request, StartImpersonation $start): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid'],
            'mode' => ['required', 'string', 'in:read,write'],
            'reason' => ['required', 'string', 'max:1000'],
            'approval_id' => ['nullable', 'uuid'],
            'owner_override' => ['sometimes', 'boolean'],
        ]);

        $started = $start->handle(
            RequestAdminActor::from($request), $data['tenant_id'], $data['user_id'], $data['mode'], $data['reason'],
            $data['approval_id'] ?? null, (bool) ($data['owner_override'] ?? false),
        );

        return response()->json(['data' => $started], 201);
    }

    public function requestWrite(Request $request, RequestImpersonationWrite $action, Clock $clock): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $approval = $action->handle(RequestAdminActor::from($request), $data['tenant_id'], $data['user_id'], $data['reason']);

        return response()->json(['data' => ApprovalsController::resource($approval, $clock)], 201);
    }

    public function end(Request $request, EndImpersonation $end, Clock $clock, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            throw DomainRuleViolation::single('not_found', 'Impersonation not found.');
        }
        $session = $end->handle(RequestAdminActor::from($request), $id);

        return response()->json(['data' => self::resource($session, $clock)]);
    }

    /** @return array<string, mixed> never the ticket or its hash */
    private static function resource(ImpersonationSession $s, Clock $clock): array
    {
        $adminName = $s->getAttributes()['admin_name'] ?? null;

        return [
            'id' => $s->id,
            'tenant_id' => $s->tenant_id,
            'admin_id' => $s->admin_id,
            'admin_name' => is_string($adminName) ? $adminName : null,
            'user_id' => $s->user_id,
            'mode' => $s->mode,
            'reason' => $s->reason,
            'owner_override' => $s->owner_override,
            'grant_id' => $s->grant_id,
            'approval_id' => $s->approval_id,
            'started_at' => $s->started_at?->format(DATE_RFC3339),
            'ends_at' => $s->ends_at->format(DATE_RFC3339),
            'ended_at' => $s->ended_at?->format(DATE_RFC3339),
            'created_at' => $s->created_at->format(DATE_RFC3339),
            'status' => $s->status($clock->now()),
        ];
    }
}
