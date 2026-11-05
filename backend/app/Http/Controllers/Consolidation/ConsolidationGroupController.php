<?php

declare(strict_types=1);

namespace App\Http\Controllers\Consolidation;

use App\Actions\Consolidation\ManageConsolidationGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Consolidation\GroupAccountRequest;
use App\Http\Requests\Consolidation\GroupRequest;
use App\Http\Requests\Consolidation\MappingRequest;
use App\Http\Requests\Consolidation\MemberRequest;
use App\Http\Requests\Consolidation\OwnershipRequest;
use App\Http\Requests\Consolidation\PeriodRangeRequest;
use App\Http\Resources\ConsolidationGroupAccountResource;
use App\Http\Resources\ConsolidationGroupResource;
use App\Http\Resources\ConsolidationMapResource;
use App\Http\Resources\UnmappedBalanceResource;
use App\Http\Support\RequestActor;
use App\Models\Consolidation\ConsolidationAccountMap;
use App\Models\Consolidation\ConsolidationGroup;
use App\Models\Consolidation\ConsolidationGroupAccount;
use App\Models\Consolidation\ConsolidationMember;
use App\Queries\Consolidation\UnmappedBalances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/**
 * Consolidation groups (D16, plan 3c.29a): the group, its members with dated ownership, the group chart and the mapping
 * of each member's accounts. Organisation-level: a person with the consolidation right and view rights in every member.
 */
final class ConsolidationGroupController extends Controller
{
    #[Authorize('viewAny', ConsolidationGroup::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $groups = ConsolidationGroup::query()->with('members.company', 'members.ownerships')->orderBy('name')->get()
            ->filter(fn (ConsolidationGroup $g) => $request->user()?->can('view', $g) === true);

        return ConsolidationGroupResource::collection($groups->values());
    }

    #[Authorize('create', ConsolidationGroup::class)]
    public function store(GroupRequest $request, ManageConsolidationGroup $action): JsonResponse
    {
        $v = $request->validated();
        $group = $action->create(RequestActor::user($request), (string) $v['name'], (string) $v['presentation_currency'], (string) $v['parent_company_id'], (int) ($v['ic_tolerance'] ?? 0));

        return (new ConsolidationGroupResource($this->fresh($group->id)))->response()->setStatusCode(201);
    }

    #[Authorize('view', 'group')]
    public function show(ConsolidationGroup $group): ConsolidationGroupResource
    {
        return new ConsolidationGroupResource($this->fresh($group->id));
    }

    #[Authorize('manage', 'group')]
    public function update(GroupRequest $request, ConsolidationGroup $group, ManageConsolidationGroup $action): ConsolidationGroupResource
    {
        $v = $request->validated();
        $action->update(RequestActor::user($request), $group->id, (string) $v['name'], (int) ($v['ic_tolerance'] ?? $group->ic_tolerance), (int) $v['version']);

        return new ConsolidationGroupResource($this->fresh($group->id));
    }

    #[Authorize('manage', 'group')]
    public function addMember(MemberRequest $request, ConsolidationGroup $group, ManageConsolidationGroup $action): JsonResponse
    {
        $v = $request->validated();
        $action->addSubsidiary(RequestActor::user($request), $group->id, (string) $v['company_id'], (string) $v['acquisition_date'], (int) $v['percent_bp']);

        return (new ConsolidationGroupResource($this->fresh($group->id)))->response()->setStatusCode(201);
    }

    #[Authorize('manage', 'member')]
    public function setOwnership(OwnershipRequest $request, ConsolidationMember $member, ManageConsolidationGroup $action): ConsolidationGroupResource
    {
        $v = $request->validated();
        $action->setOwnership(RequestActor::user($request), $member->id, (string) $v['effective_on'], (int) $v['percent_bp'], isset($v['note']) ? (string) $v['note'] : null);

        return new ConsolidationGroupResource($this->fresh($member->group_id));
    }

    #[Authorize('view', 'group')]
    public function accounts(ConsolidationGroup $group): AnonymousResourceCollection
    {
        return ConsolidationGroupAccountResource::collection(ConsolidationGroupAccount::query()->where('group_id', $group->id)->orderBy('code')->get());
    }

    #[Authorize('manage', 'group')]
    public function storeAccount(GroupAccountRequest $request, ConsolidationGroup $group, ManageConsolidationGroup $action): JsonResponse
    {
        $v = $request->validated();
        $account = $action->saveAccount(RequestActor::user($request), $group->id, null, (string) $v['code'], (string) $v['name'], (string) $v['account_type'], (string) $v['account_subtype']);

        return (new ConsolidationGroupAccountResource($account))->response()->setStatusCode(201);
    }

    #[Authorize('manage', 'group')]
    public function updateAccount(GroupAccountRequest $request, ConsolidationGroup $group, ConsolidationGroupAccount $groupAccount, ManageConsolidationGroup $action): ConsolidationGroupAccountResource
    {
        $v = $request->validated();

        return new ConsolidationGroupAccountResource($action->saveAccount(RequestActor::user($request), $group->id, $groupAccount->id, (string) $v['code'], (string) $v['name'], (string) $v['account_type'], (string) $v['account_subtype']));
    }

    #[Authorize('view', 'member')]
    public function mapping(ConsolidationMember $member): AnonymousResourceCollection
    {
        return ConsolidationMapResource::collection(ConsolidationAccountMap::query()->where('member_id', $member->id)->orderBy('account_id')->get());
    }

    #[Authorize('manage', 'member')]
    public function map(MappingRequest $request, ConsolidationMember $member, ManageConsolidationGroup $action): AnonymousResourceCollection
    {
        return ConsolidationMapResource::collection($action->mapAccounts(RequestActor::user($request), $member->id, $request->pairs()));
    }

    /** Accounts with a balance or movement and no mapping; any of them blocks a run. */
    #[Authorize('view', 'group')]
    public function unmapped(PeriodRangeRequest $request, ConsolidationGroup $group, UnmappedBalances $unmapped): AnonymousResourceCollection
    {
        return UnmappedBalanceResource::collection($unmapped($group->id, $request->from(), $request->to()));
    }

    private function fresh(string $id): ConsolidationGroup
    {
        return ConsolidationGroup::query()->with('members.company', 'members.ownerships')->findOrFail($id);
    }
}
