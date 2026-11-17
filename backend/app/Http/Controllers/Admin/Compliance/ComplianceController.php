<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Compliance;

use App\Actions\Admin\Compliance\OpenGdprRequest;
use App\Actions\Admin\Compliance\RequestLegalHoldRelease;
use App\Actions\Admin\Compliance\RequestTenantDeletion;
use App\Actions\Admin\Compliance\SetArchiveUntil;
use App\Actions\Admin\Compliance\SetLegalHold;
use App\Actions\Admin\Compliance\UpdateGdprRequest;
use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Admin\ApprovalsController;
use App\Http\Controllers\Admin\Tenants\TenantsController;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Queries\Admin\Compliance\ListGdprRequests;
use App\Queries\Admin\Compliance\RetentionSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Compliance (`compliance.manage`, plan Task 18): deletion requests, legal hold, retention and the GDPR queue. */
final class ComplianceController extends Controller
{
    private const array KINDS = ['export', 'erasure', 'rectification', 'access'];

    private const array STATUSES = ['open', 'in_progress', 'done', 'rejected'];

    public function requestDeletion(Request $request, RequestTenantDeletion $action, Clock $clock, string $tenant): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $approval = $action->handle(RequestAdminActor::from($request), TenantsController::uuid($tenant), $data['reason']);

        return response()->json(['data' => ApprovalsController::resource($approval, $clock)], 202);
    }

    public function setLegalHold(Request $request, SetLegalHold $action, string $tenant): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $id = TenantsController::uuid($tenant);
        $action->set(RequestAdminActor::from($request), $id, $data['reason']);

        return response()->json(['data' => ['tenant_id' => $id, 'legal_hold' => true]]);
    }

    public function requestHoldRelease(Request $request, RequestLegalHoldRelease $action, Clock $clock, string $tenant): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $approval = $action->handle(RequestAdminActor::from($request), TenantsController::uuid($tenant), $data['reason']);

        return response()->json(['data' => ApprovalsController::resource($approval, $clock)], 202);
    }

    public function archiveUntil(Request $request, SetArchiveUntil $action, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'archive_until' => ['present', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $id = TenantsController::uuid($tenant);
        $action->handle(RequestAdminActor::from($request), $id, $data['archive_until'], $data['reason']);

        return response()->json(['data' => ['tenant_id' => $id, 'archive_until' => $data['archive_until']]]);
    }

    public function retention(RetentionSchedule $schedule): JsonResponse
    {
        return response()->json(['data' => $schedule()]);
    }

    public function gdprIndex(Request $request, ListGdprRequests $list): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'kind' => ['nullable', Rule::in(self::KINDS)],
            'tenant_id' => ['nullable', 'uuid'],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $filters['overdue'] = $request->boolean('overdue');

        return response()->json(['data' => $list($filters), 'meta' => ['fulfilment' => 'manual', 'fulfilment_note' => ListGdprRequests::FULFILMENT_NOTE]]);
    }

    public function gdprStore(Request $request, OpenGdprRequest $open, ListGdprRequests $list): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'tenant_id' => ['required', 'uuid', 'exists:pgsql_platform.tenants,id'],
            'subject_email' => ['required', 'email:rfc', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $id = $open->handle(RequestAdminActor::from($request), $data['kind'], $data['tenant_id'], $data['subject_email'], $data['note'] ?? null);

        return response()->json(['data' => $list->find($id)], 201);
    }

    public function gdprUpdate(Request $request, UpdateGdprRequest $update, ListGdprRequests $list, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $requestId = Str::isUuid($id) ? $id : throw DomainRuleViolation::single('not_found', 'Request not found.');

        $update->handle(RequestAdminActor::from($request), $requestId, $data['status'], $data['note'] ?? null);

        return response()->json(['data' => $list->find($requestId)]);
    }
}
