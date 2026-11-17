<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Actions\Admin\Support\ForgetFailedJob;
use App\Actions\Admin\Support\RetryFailedJob;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Admin\FailedJob;
use App\Models\Admin\MailLogEntry;
use App\Queries\Admin\Support\FailedJobs;
use App\Queries\Admin\Support\GlobalSearch;
use App\Queries\Admin\Support\MailLog;
use App\Queries\Admin\Support\WebhookLog;
use App\Queries\Admin\Tenants\ShowTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use LogicException;

/** Admin support tools (plan Task 15): global search, failed jobs, mail log, webhook deliveries. Gates are on the routes. */
final class SupportController extends Controller
{
    public function search(Request $request, GlobalSearch $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return response()->json($search(trim($data['q'])));
    }

    public function failedJobs(Request $request, FailedJobs $jobs): JsonResponse
    {
        $page = $jobs($request);

        return response()->json([
            'data' => $page->items->map(function ($j): array {
                $job = $j instanceof FailedJob ? $j : throw new LogicException;

                return [
                    'id' => $job->uuid, 'job' => $job->job, 'queue' => $job->queue, 'connection' => $job->connection_name,
                    'failed_at' => (new \DateTimeImmutable((string) $job->failed_at, new \DateTimeZone('UTC')))->format(DATE_ATOM),
                    'exception' => $job->exception_line,
                ];
            })->all(),
            'meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit],
        ]);
    }

    public function retry(Request $request, RetryFailedJob $retry, string $uuid): JsonResponse
    {
        return response()->json(['data' => $retry->handle(RequestAdminActor::from($request), $uuid)]);
    }

    public function forget(Request $request, ForgetFailedJob $forget, string $uuid): Response
    {
        $forget->handle(RequestAdminActor::from($request), $uuid);

        return response()->noContent();
    }

    public function mailLog(Request $request, MailLog $log): JsonResponse
    {
        $filters = $request->validate(['tenant' => ['nullable', 'uuid']]);
        $page = $log($request, $filters['tenant'] ?? null);

        return response()->json([
            'data' => $page->items->map(function ($m): array {
                $entry = $m instanceof MailLogEntry ? $m : throw new LogicException;

                return [
                    'id' => $entry->id, 'tenant_id' => $entry->tenant_id, 'to' => $entry->to_masked, 'subject' => $entry->subject,
                    'notification' => $entry->notification, 'status' => $entry->status, 'sent_at' => $entry->sent_at->format(DATE_ATOM),
                ];
            })->all(),
            'meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit],
        ]);
    }

    public function webhookDeliveries(Request $request, WebhookLog $log, string $tenant): JsonResponse
    {
        $limit = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:200']])['limit'] ?? 50;
        if (! Str::isUuid($tenant)) {
            throw ShowTenant::notFound();
        }
        ShowTenant::mustExist($tenant);

        return response()->json(['data' => $log($tenant, (int) $limit)]);
    }
}
