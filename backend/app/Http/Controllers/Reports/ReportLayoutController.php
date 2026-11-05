<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\DeleteReportLayout;
use App\Actions\Reports\SaveReportLayout;
use App\Domain\Reports\LayoutParams;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportLayoutRequest;
use App\Http\Resources\ReportLayoutResource;
use App\Http\Support\RequestActor;
use App\Models\Organisation\Company;
use App\Models\Reports\ReportLayout;
use App\Support\Reports\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Validation\Rule;

/**
 * Saved report layouts (plan 3c.23): a name for a report's chosen parameters, private to the person or shared with the company.
 * Anyone who can view reports keeps their own; only the owner changes or removes one. Nothing here reads or writes the ledger.
 */
final class ReportLayoutController extends Controller
{
    /** The layouts the person can use: their own and the ones shared with the company; the default first. */
    #[Authorize('layouts', Report::class)]
    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $request->validate(['report' => ['sometimes', 'string', Rule::in(LayoutParams::REPORTS)]]);
        $me = (string) $request->user()?->getAuthIdentifier();
        $layouts = ReportLayout::query()->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('user_id', $me)->orWhere('visibility', 'company'))
            ->when($request->query('report') !== null, fn ($q) => $q->where('report_code', (string) $request->query('report')))
            ->orderByRaw('(user_id = ? AND is_default) DESC', [$me])->orderBy('name')->orderBy('id')->get();

        return ReportLayoutResource::collection($layouts);
    }

    #[Authorize('layouts', Report::class)]
    public function store(ReportLayoutRequest $request, Company $company, SaveReportLayout $action): JsonResponse
    {
        $layout = $action->handle(RequestActor::user($request, $company->id), $request->toInput());

        return (new ReportLayoutResource($layout))->response()->setStatusCode(201);
    }

    #[Authorize('layouts', Report::class)]
    public function update(ReportLayoutRequest $request, Company $company, string $layout, SaveReportLayout $action): ReportLayoutResource
    {
        return new ReportLayoutResource($action->handle(RequestActor::user($request, $company->id), $request->toInput($layout)));
    }

    #[Authorize('layouts', Report::class)]
    public function destroy(Request $request, Company $company, string $layout, DeleteReportLayout $action): JsonResponse
    {
        $action->handle(RequestActor::user($request, $company->id), $company->id, $layout);

        return response()->json(null, 204);
    }
}
