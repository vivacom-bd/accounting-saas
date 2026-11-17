<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAuditEventResource;
use App\Models\Admin\AdminAuditEvent;
use App\Queries\Admin\ListAdminAudit;
use App\Queries\Admin\VerifyAdminAuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The admin audit viewer (`audit.view`): the log, cursor-paged, and the chain check. */
final class AdminAuditController extends Controller
{
    public function index(Request $request, ListAdminAudit $audit): JsonResponse
    {
        $filters = $request->validate([
            'admin' => ['nullable', 'uuid'],
            'tenant' => ['nullable', 'uuid'],
            'action' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $page = $audit($request, $filters);

        return response()->json([
            'data' => $page->items->map(fn ($e): array => AdminAuditEventResource::make($e instanceof AdminAuditEvent ? $e : throw new \LogicException))->all(),
            'meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit],
        ]);
    }

    public function verify(VerifyAdminAuditChain $verify): JsonResponse
    {
        $report = $verify();

        return response()->json(['data' => ['intact' => $report['brokenAtId'] === null, 'events' => $report['events'], 'broken_id' => $report['brokenAtId']]]);
    }
}
