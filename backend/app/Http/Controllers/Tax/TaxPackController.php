<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tax;

use App\Actions\Tax\ApplyCountryPack;
use App\Actions\Tax\ManageTaxPack;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tax\TaxPackRequest;
use App\Http\Resources\FreeFormDataResource;
use App\Http\Support\RequestActor;
use App\Models\Organisation\Company;
use App\Models\Tax\TaxCode;
use App\Queries\Tax\TaxPackState;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/** Country packs: what is on offer and applied, applying one, the company's settings and the mapping of tax codes to return sections. */
final class TaxPackController extends Controller
{
    #[Authorize('viewAny', TaxCode::class)]
    public function show(Company $company, TaxPackState $state): FreeFormDataResource
    {
        return new FreeFormDataResource($state($company->id));
    }

    #[Authorize('create', TaxCode::class)]
    public function apply(TaxPackRequest $request, Company $company, ApplyCountryPack $action, TaxPackState $state): FreeFormDataResource
    {
        $result = $action->handle(RequestActor::user($request, $company->id), $company->id, $request->packKey());

        return new FreeFormDataResource(['result' => $result] + $state($company->id));
    }

    #[Authorize('create', TaxCode::class)]
    public function settings(TaxPackRequest $request, Company $company, ManageTaxPack $action, TaxPackState $state): FreeFormDataResource
    {
        $action->updateSettings(RequestActor::user($request, $company->id), $company->id, $request->version(), $request->authority(), $request->dueDays(), $request->reverseChargeMode());

        return new FreeFormDataResource($state($company->id));
    }

    #[Authorize('create', TaxCode::class)]
    public function sectionCodes(TaxPackRequest $request, Company $company, string $section, ManageTaxPack $action, TaxPackState $state): FreeFormDataResource
    {
        $action->setSectionCodes(RequestActor::user($request, $company->id), $company->id, $section, $request->codes());

        return new FreeFormDataResource($state($company->id));
    }
}
