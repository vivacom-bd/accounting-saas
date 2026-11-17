<?php

declare(strict_types=1);

namespace App\Http\Controllers\EInvoicing;

use App\Actions\EInvoicing\ManageEInvoicing;
use App\Http\Controllers\Controller;
use App\Http\Requests\EInvoicing\EInvoicingRequest;
use App\Http\Resources\FreeFormDataResource;
use App\Http\Support\RequestActor;
use App\Http\Support\RequestUser;
use App\Models\EInvoicing\CompanyEInvoicing;
use App\Models\EInvoicing\EInvoiceSubmission;
use App\Models\Organisation\Company;
use App\Services\EInvoicing\EInvoiceAdapters;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/** E-invoicing: the company's settings (off by default), the status of a document with the authority, and a manual retry. */
final class EInvoicingController extends Controller
{
    #[Authorize('viewAny', CompanyEInvoicing::class)]
    public function show(Company $company, EInvoiceAdapters $adapters): FreeFormDataResource
    {
        return new FreeFormDataResource($this->settings($company, $adapters));
    }

    #[Authorize('manage', CompanyEInvoicing::class)]
    public function update(EInvoicingRequest $request, Company $company, ManageEInvoicing $action, EInvoiceAdapters $adapters): FreeFormDataResource
    {
        $action->update(RequestActor::user($request, $company->id), $company->id, $request->version(), $request->enabled(), $request->adapter(), $request->taxId(), $request->branch());

        return new FreeFormDataResource($this->settings($company, $adapters));
    }

    /** Where a posted sales document stands with the authority; `not_required` when it was never queued. */
    #[Authorize('viewAny', CompanyEInvoicing::class)]
    public function status(Request $request, Company $company, string $document): FreeFormDataResource
    {
        $manage = RequestUser::principal($request)?->can('manage', CompanyEInvoicing::class) === true;
        $s = EInvoiceSubmission::query()->where('company_id', $company->id)->where('invoice_id', $document)->orderByDesc('document_version')->first();
        $settings = CompanyEInvoicing::query()->where('company_id', $company->id)->first();

        return new FreeFormDataResource($s === null ? ['status' => 'not_required', 'enabled' => (bool) ($settings->enabled ?? false), 'can_retry' => false] : [
            'status' => $s->status, 'enabled' => (bool) ($settings->enabled ?? false), 'attempts' => $s->attempts,
            'next_attempt_at' => $s->next_attempt_at?->format('Y-m-d\TH:i:s\Z'), 'dead_lettered' => $s->dead_lettered_at !== null,
            'last_error_code' => $s->last_error_code, 'last_error_message' => $s->last_error_message,
            'authority_number' => $s->authority_number, 'authority_timestamp' => $s->authority_timestamp?->format('Y-m-d\TH:i:s\Z'), 'device_id' => $s->device_id,
            'can_retry' => $manage && in_array($s->status, ['failed_retryable', 'unknown'], true),
        ]);
    }

    #[Authorize('manage', CompanyEInvoicing::class)]
    public function retry(Request $request, Company $company, string $document, ManageEInvoicing $action): FreeFormDataResource
    {
        $s = $action->retry(RequestActor::user($request, $company->id), $company->id, $document);
        $s->refresh();

        return new FreeFormDataResource(['status' => $s->status, 'attempts' => $s->attempts]);
    }

    /** @return array<string, mixed> */
    private function settings(Company $company, EInvoiceAdapters $adapters): array
    {
        $row = CompanyEInvoicing::query()->where('company_id', $company->id)->first();

        return [
            'enabled' => (bool) ($row->enabled ?? false), 'adapter' => $row->adapter_key ?? null, 'tax_id' => $row->seller_tax_id ?? null, 'branch' => $row->seller_branch ?? null,
            'version' => $row?->lock_version,
            'adapters' => array_values(array_map(fn ($a): array => ['key' => $a->key(), 'label' => $a->label()], $adapters->selectable())),
        ];
    }
}
