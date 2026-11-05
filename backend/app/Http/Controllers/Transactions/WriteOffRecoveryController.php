<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transactions;

use App\Actions\Transactions\ReverseWriteOffRecovery;
use App\Actions\Transactions\SaveWriteOffRecoveryDraft;
use App\Actions\Workflow\ApproveDocument;
use App\Actions\Workflow\Inputs\DocumentRef;
use App\Actions\Workflow\Inputs\ReturnDocumentInput;
use App\Actions\Workflow\Inputs\VoidDocumentInput;
use App\Actions\Workflow\ReturnDocument;
use App\Actions\Workflow\SubmitDocument;
use App\Actions\Workflow\VoidDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ledger\ReturnJournalRequest;
use App\Http\Requests\Ledger\ReverseJournalRequest;
use App\Http\Requests\Ledger\VoidJournalRequest;
use App\Http\Requests\Trade\ListPaymentRunsRequest;
use App\Http\Requests\Transactions\WriteOffRecoveryRequest;
use App\Http\Requests\TransitionRequest;
use App\Http\Resources\InvoicePostingPreviewResource;
use App\Http\Resources\WriteOffRecoveryResource;
use App\Http\Support\CursorPage;
use App\Http\Support\RequestActor;
use App\Models\Organisation\Company;
use App\Models\Transactions\WriteOffRecovery;
use App\Queries\Transactions\PreviewWriteOffRecoveryPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/** Recoveries after write-off (`write_offs.*`): an approved document against one posted write-off; the money arrives through a normal receipt. Tokens read only. */
final class WriteOffRecoveryController extends Controller
{
    #[Authorize('viewAny', WriteOffRecovery::class)]
    public function index(ListPaymentRunsRequest $request, Company $company): AnonymousResourceCollection
    {
        $query = WriteOffRecovery::query()->where('company_id', $company->id);
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('write_off_id')) {
            $query->where('write_off_id', (string) $request->query('write_off_id'));
        }
        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', (string) $request->query('invoice_id'));
        }
        $page = CursorPage::fetch($query, $request, 'recovery_date', descending: true, kind: 'date');

        return WriteOffRecoveryResource::collection($page->items)->additional(['meta' => ['next_cursor' => $page->nextCursor, 'prev_cursor' => $page->prevCursor, 'limit' => $page->limit]]);
    }

    #[Authorize('view', 'writeOffRecovery')]
    public function show(Company $company, WriteOffRecovery $writeOffRecovery): WriteOffRecoveryResource
    {
        return new WriteOffRecoveryResource($writeOffRecovery);
    }

    #[Authorize('create', WriteOffRecovery::class)]
    public function store(WriteOffRecoveryRequest $request, Company $company, SaveWriteOffRecoveryDraft $action): JsonResponse
    {
        return (new WriteOffRecoveryResource($action->handle(RequestActor::from($request, $company->id), $request->toInput())))->response()->setStatusCode(201);
    }

    #[Authorize('update', 'writeOffRecovery')]
    public function update(WriteOffRecoveryRequest $request, Company $company, WriteOffRecovery $writeOffRecovery, SaveWriteOffRecoveryDraft $action): WriteOffRecoveryResource
    {
        return new WriteOffRecoveryResource($action->handle(RequestActor::from($request, $company->id), $request->toInput(), $writeOffRecovery->id, $request->version()));
    }

    #[Authorize('view', 'writeOffRecovery')]
    public function preview(Request $request, Company $company, WriteOffRecovery $writeOffRecovery, PreviewWriteOffRecoveryPosting $preview): InvoicePostingPreviewResource
    {
        return new InvoicePostingPreviewResource($preview->of(RequestActor::from($request, $company->id), $writeOffRecovery->id));
    }

    #[Authorize('submit', 'writeOffRecovery')]
    public function submit(TransitionRequest $request, Company $company, WriteOffRecovery $writeOffRecovery, SubmitDocument $action): WriteOffRecoveryResource
    {
        $action->handle(RequestActor::from($request, $company->id), $this->ref($writeOffRecovery, $request->version()));

        return new WriteOffRecoveryResource($this->fresh($writeOffRecovery->id));
    }

    #[Authorize('approve', 'writeOffRecovery')]
    public function approve(TransitionRequest $request, Company $company, WriteOffRecovery $writeOffRecovery, ApproveDocument $action): WriteOffRecoveryResource
    {
        $action->handle(RequestActor::from($request, $company->id), $this->ref($writeOffRecovery, $request->version()), $request->comment());

        return new WriteOffRecoveryResource($this->fresh($writeOffRecovery->id));
    }

    #[Authorize('return', 'writeOffRecovery')]
    public function return(ReturnJournalRequest $request, Company $company, WriteOffRecovery $writeOffRecovery, ReturnDocument $action): WriteOffRecoveryResource
    {
        $action->handle(RequestActor::from($request, $company->id), new ReturnDocumentInput($this->ref($writeOffRecovery, $request->version()), $request->comment()));

        return new WriteOffRecoveryResource($this->fresh($writeOffRecovery->id));
    }

    #[Authorize('void', 'writeOffRecovery')]
    public function void(VoidJournalRequest $request, Company $company, WriteOffRecovery $writeOffRecovery, VoidDocument $action): WriteOffRecoveryResource
    {
        $action->handle(RequestActor::from($request, $company->id), new VoidDocumentInput($this->ref($writeOffRecovery, $request->version()), $request->reason()));

        return new WriteOffRecoveryResource($this->fresh($writeOffRecovery->id));
    }

    #[Authorize('reverse', 'writeOffRecovery')]
    public function reverse(ReverseJournalRequest $request, Company $company, WriteOffRecovery $writeOffRecovery, ReverseWriteOffRecovery $action): WriteOffRecoveryResource
    {
        $in = $request->toInput($writeOffRecovery->id);
        $action->handle(RequestActor::from($request, $company->id), $writeOffRecovery->id, $in->date, $in->lockVersion, $in->memo);

        return new WriteOffRecoveryResource($this->fresh($writeOffRecovery->id));
    }

    private function ref(WriteOffRecovery $r, ?int $version): DocumentRef
    {
        return new DocumentRef('write_off_recovery', $r->id, $version);
    }

    private function fresh(string $id): WriteOffRecovery
    {
        return WriteOffRecovery::query()->findOrFail($id);
    }
}
