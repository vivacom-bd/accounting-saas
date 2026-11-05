<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\DeleteReportSchedule;
use App\Actions\Reports\SaveReportSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportScheduleRequest;
use App\Http\Resources\ReportScheduleResource;
use App\Http\Support\RequestActor;
use App\Models\Organisation\Company;
use App\Models\Reports\ReportLayout;
use App\Models\Reports\ReportSchedule;
use App\Queries\Reports\ScheduleRecipients;
use App\Support\Reports\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/**
 * Scheduled email delivery of a layout (plan 3c.23): the schedule, its run history, and the people it can be mailed to. Setting one
 * needs `reports.schedule`; seeing or stopping your own does not. A schedule runs as the layout's owner.
 */
final class ReportScheduleController extends Controller
{
    /** The schedule on one of your layouts with its recent runs; 404 when there is none. */
    #[Authorize('layouts', Report::class)]
    public function show(Request $request, Company $company, string $layout): ReportScheduleResource
    {
        $row = ReportLayout::query()->where('company_id', $company->id)->where('user_id', (string) $request->user()?->getAuthIdentifier())->whereKey($layout)->firstOrFail();

        return new ReportScheduleResource(ReportSchedule::query()->where('layout_id', $row->id)->firstOrFail());
    }

    #[Authorize('schedule', Report::class)]
    public function upsert(ReportScheduleRequest $request, Company $company, string $layout, SaveReportSchedule $action): JsonResponse
    {
        return (new ReportScheduleResource($action->handle(RequestActor::user($request, $company->id), $request->toInput($layout))))->response()->setStatusCode(200);
    }

    #[Authorize('layouts', Report::class)]
    public function destroy(Request $request, Company $company, string $layout, DeleteReportSchedule $action): JsonResponse
    {
        $action->handle(RequestActor::user($request, $company->id), $company->id, $layout);

        return response()->json(null, 204);
    }

    /**
     * The people a scheduled report can be mailed to by name: members who can work in this company.
     *
     * @return array{data: list<array{id: string, name: string, email: string}>}
     */
    #[Authorize('schedule', Report::class)]
    public function recipients(Company $company, ScheduleRecipients $people): array
    {
        return ['data' => $people($company->id)];
    }
}
