<?php

declare(strict_types=1);

namespace App\Http\Controllers\Consolidation;

use App\Actions\Consolidation\Inputs\EntryDraftInput;
use App\Actions\Consolidation\ManageConsolidationEntry;
use App\Actions\Consolidation\ManageConsolidationGroup;
use App\Actions\Consolidation\ManageConsolidationRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Consolidation\EntryRequest;
use App\Http\Requests\Consolidation\PeriodRangeRequest;
use App\Http\Requests\Consolidation\RateOverrideRequest;
use App\Http\Requests\Consolidation\ReasonRequest;
use App\Http\Resources\ConsolidationEntryResource;
use App\Http\Resources\ConsolidationIcReportResource;
use App\Http\Resources\ConsolidationRateResource;
use App\Http\Resources\ConsolidationRunResource;
use App\Http\Resources\ConsolidationWorksheetResource;
use App\Http\Support\CursorPage;
use App\Http\Support\RequestActor;
use App\Models\Consolidation\ConsolidationEntry;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/**
 * Consolidation runs and the consolidation ledger (D16.3, plan 3c.29b): compute a period (read-only on the members' books),
 * approve it by a second person, reverse it; manual group entries are drafted, posted by a second person, and reversed.
 */
final class ConsolidationRunController extends Controller
{
    #[Authorize('view', 'group')]
    public function index(Request $request, ConsolidationGroup $group): AnonymousResourceCollection
    {
        $query = ConsolidationRun::query()->where('group_id', $group->id);
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        $page = CursorPage::fetch($query, $request, 'period_to', descending: true, kind: 'date');

        return ConsolidationRunResource::collection($page->items)->additional(['meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit]]);
    }

    /** Computes the period from the members' books and freezes the worksheet; nothing is posted until a second person approves. */
    #[Authorize('run', 'group')]
    public function store(PeriodRangeRequest $request, ConsolidationGroup $group, ManageConsolidationRun $action): JsonResponse
    {
        $run = $action->create(RequestActor::user($request), $group->id, $request->from(), $request->to());

        return (new ConsolidationRunResource($run))->response()->setStatusCode(201);
    }

    #[Authorize('view', 'run')]
    public function show(ConsolidationRun $run): ConsolidationRunResource
    {
        return new ConsolidationRunResource($run);
    }

    /** The frozen worksheet: each member in its own currency and translated, every adjustment, the consolidated total. */
    #[Authorize('view', 'run')]
    public function worksheet(ConsolidationRun $run): ConsolidationWorksheetResource
    {
        return new ConsolidationWorksheetResource($run);
    }

    /** The intercompany matching report of the run (D16.7), in the presentation currency after translation. */
    #[Authorize('view', 'run')]
    public function intercompany(ConsolidationRun $run): ConsolidationIcReportResource
    {
        return new ConsolidationIcReportResource($run);
    }

    /** A second person accepts an intercompany difference above the group's tolerance, with a reason; it is not eliminated. */
    #[Authorize('approve', 'run')]
    public function acceptDifference(ReasonRequest $request, ConsolidationRun $run, string $match, ManageConsolidationRun $action): ConsolidationIcReportResource
    {
        $action->acceptDifference(RequestActor::user($request), $run->id, $match, $request->reason());

        return new ConsolidationIcReportResource($run);
    }

    #[Authorize('approve', 'run')]
    public function approve(Request $request, ConsolidationRun $run, ManageConsolidationRun $action): ConsolidationRunResource
    {
        return new ConsolidationRunResource($action->approve(RequestActor::user($request), $run->id));
    }

    #[Authorize('run', 'run')]
    public function void(ReasonRequest $request, ConsolidationRun $run, ManageConsolidationRun $action): ConsolidationRunResource
    {
        return new ConsolidationRunResource($action->void(RequestActor::user($request), $run->id, $request->reason()));
    }

    /** Reverses an approved run by reversing its entries; only the latest approved run of a group. */
    #[Authorize('approve', 'run')]
    public function reverse(ReasonRequest $request, ConsolidationRun $run, ManageConsolidationRun $action): ConsolidationRunResource
    {
        return new ConsolidationRunResource($action->reverse(RequestActor::user($request), $run->id, $request->reason()));
    }

    #[Authorize('view', 'group')]
    public function entries(Request $request, ConsolidationGroup $group): AnonymousResourceCollection
    {
        $query = ConsolidationEntry::query()->with('group')->where('group_id', $group->id);
        foreach (['status', 'kind'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }
        $page = CursorPage::fetch($query, $request, 'entry_date', descending: true, kind: 'date');

        return ConsolidationEntryResource::collection($page->items)->additional(['meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit]]);
    }

    #[Authorize('view', 'entry')]
    public function showEntry(ConsolidationEntry $entry): ConsolidationEntryResource
    {
        return new ConsolidationEntryResource($entry->load('lines', 'group'));
    }

    /** A manual group entry, for example unrealised profit in inventory (there is no inventory module to derive it). Posts when a second person approves. */
    #[Authorize('manage', 'group')]
    public function storeEntry(EntryRequest $request, ConsolidationGroup $group, ManageConsolidationEntry $action): JsonResponse
    {
        $v = $request->validated();
        $entry = $action->draftManual(RequestActor::user($request), new EntryDraftInput($group->id, (string) ($v['kind'] ?? 'manual'), (string) $v['entry_date'], $request->lines(), (string) $v['memo']));

        return (new ConsolidationEntryResource($entry->load('lines', 'group')))->response()->setStatusCode(201);
    }

    #[Authorize('approve', 'entry')]
    public function approveEntry(Request $request, ConsolidationEntry $entry, ManageConsolidationEntry $action): ConsolidationEntryResource
    {
        return new ConsolidationEntryResource($action->approve(RequestActor::user($request), $entry->id)->load('lines', 'group'));
    }

    #[Authorize('manage', 'entry')]
    public function voidEntry(Request $request, ConsolidationEntry $entry, ManageConsolidationEntry $action): ConsolidationEntryResource
    {
        return new ConsolidationEntryResource($action->void(RequestActor::user($request), $entry->id)->load('lines', 'group'));
    }

    #[Authorize('approve', 'entry')]
    public function reverseEntry(ReasonRequest $request, ConsolidationEntry $entry, ManageConsolidationEntry $action): ConsolidationEntryResource
    {
        return new ConsolidationEntryResource($action->reverse(RequestActor::user($request), $entry->id, $request->reason())->load('lines', 'group'));
    }

    /** Sets the average rate for a currency and run period where the default (the mean of the rate in force on each day) does not approximate the transaction rates (D16.4). */
    #[Authorize('manage', 'group')]
    public function setRate(RateOverrideRequest $request, ConsolidationGroup $group, ManageConsolidationGroup $action): JsonResponse
    {
        $v = $request->validated();
        $row = $action->setRateOverride(RequestActor::user($request), $group->id, (string) $v['currency'], (string) $v['period_from'], (string) $v['period_to'], (string) $v['rate'], (string) $v['reason']);

        return (new ConsolidationRateResource($row))->response()->setStatusCode(201);
    }
}
