<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tax;

use App\Actions\Tax\ManageTaxPeriods;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\TaxPeriodRequest;
use App\Http\Resources\TaxPeriodResource;
use App\Http\Support\RequestActor;
use App\Models\Organisation\Company;
use App\Models\Tax\TaxPeriod;
use App\Queries\Tax\TaxPeriodReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/** Tax periods: list, one with its return and "changed since filing", generate, manual amounts, file, reopen. */
final class TaxPeriodController extends Controller
{
    public const string DEFAULT_AUTHORITY = 'Tax authority';

    #[Authorize('viewAny', TaxPeriod::class)]
    public function index(Company $company, TaxPeriodReturn $returns): AnonymousResourceCollection
    {
        $periods = TaxPeriod::query()->where('company_id', $company->id)->orderByDesc('starts_on')->limit(200)->get();
        foreach ($periods as $p) {
            // Not a column: read by the resource only.
            $p->setAttribute('changed_since_filing', $p->status === TaxPeriod::FILED && $returns->changesSinceFiling($company, $p) !== []);
        }

        return TaxPeriodResource::collection($periods);
    }

    #[Authorize('view', 'taxPeriod')]
    public function show(Company $company, TaxPeriod $taxPeriod, TaxPeriodReturn $returns): TaxPeriodResource
    {
        $this->own($company, $taxPeriod);
        $changes = $returns->changesSinceFiling($company, $taxPeriod);

        foreach (['return' => $returns->current($company, $taxPeriod), 'filed_return' => $taxPeriod->return_snapshot, 'changed_since_filing' => $changes !== [], 'changes_since_filing' => $changes] as $k => $v) {
            $taxPeriod->setAttribute($k, $v);
        }

        return new TaxPeriodResource($taxPeriod);
    }

    #[Authorize('create', TaxPeriod::class)]
    public function store(TaxPeriodRequest $request, Company $company, ManageTaxPeriods $action): JsonResponse
    {
        $created = $action->generate(RequestActor::user($request, $company->id), $company->id, $request->authority() ?? self::DEFAULT_AUTHORITY, $request->from(), $request->months());

        return response()->json(['data' => array_map(fn (TaxPeriod $p): array => (new TaxPeriodResource($p))->resolve($request), $created), 'meta' => ['created' => count($created)]], 201);
    }

    #[Authorize('update', 'taxPeriod')]
    public function amounts(TaxPeriodRequest $request, Company $company, TaxPeriod $taxPeriod, ManageTaxPeriods $action): TaxPeriodResource
    {
        $this->own($company, $taxPeriod);

        return new TaxPeriodResource($action->saveManualAmounts(RequestActor::user($request, $company->id), $taxPeriod->id, $request->version(), $request->amounts()));
    }

    #[Authorize('file', 'taxPeriod')]
    public function file(TaxPeriodRequest $request, Company $company, TaxPeriod $taxPeriod, ManageTaxPeriods $action): TaxPeriodResource
    {
        $this->own($company, $taxPeriod);

        return new TaxPeriodResource($action->file(RequestActor::user($request, $company->id), $taxPeriod->id, $request->version(), $request->reference()));
    }

    #[Authorize('reopen', 'taxPeriod')]
    public function reopen(TaxPeriodRequest $request, Company $company, TaxPeriod $taxPeriod, ManageTaxPeriods $action): TaxPeriodResource
    {
        $this->own($company, $taxPeriod);

        return new TaxPeriodResource($action->reopen(RequestActor::user($request, $company->id), $taxPeriod->id, $request->version(), $request->reason()));
    }

    /** RLS separates tenants, not companies: another company's period is a 404 here. */
    private function own(Company $company, TaxPeriod $period): void
    {
        abort_unless($period->company_id === $company->id, 404);
    }
}
