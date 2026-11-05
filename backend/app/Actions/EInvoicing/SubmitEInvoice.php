<?php

declare(strict_types=1);

namespace App\Actions\EInvoicing;

use App\Contracts\Clock;
use App\Domain\EInvoicing\EInvoiceFailure;
use App\Domain\EInvoicing\EInvoiceResult;
use App\Domain\EInvoicing\EInvoiceRetryPolicy;
use App\Models\EInvoicing\CompanyEInvoicing;
use App\Models\EInvoicing\EInvoiceSubmission;
use App\Models\Trade\Invoice;
use App\Services\EInvoicing\EInvoiceAdapters;
use App\Services\EInvoicing\EInvoiceRequestBuilder;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use Throwable;

/**
 * One attempt to get a posted document accepted by the authority (plan 3c.27), run from a queued job or the sweep, never from the
 * posting transaction, so posting never waits for or fails because of an authority.
 *
 *   1. claim in its own transaction: status `submitting`, attempts + 1, a 5-minute lease on `next_attempt_at`;
 *   2. call the adapter with NO transaction open (a submission that was `unknown` or died while `submitting` is queried by its
 *      idempotency key first, and only sent again if the authority has nothing);
 *   3. record the outcome in a second transaction, with an audit event.
 *
 * A credit note waits (without using an attempt) for the invoice it credits to be accepted, so it can name the invoice's authority number.
 */
final class SubmitEInvoice
{
    public const int LEASE_SECONDS = 300;

    public const int WAIT_FOR_ORIGINAL_SECONDS = 300;

    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly Clock $clock,
        private readonly EInvoiceAdapters $adapters,
        private readonly EInvoiceRequestBuilder $builder,
    ) {}

    /** @return string|null the status after this attempt; null when there was nothing to do */
    public function handle(string $tenantId, string $submissionId): ?string
    {
        $actor = Actor::system($tenantId);
        $claim = $this->claim($actor, $submissionId);
        if ($claim === null) {
            return null;
        }

        try {
            $submission = EInvoiceSubmission::query()->findOrFail($submissionId);
            $settings = CompanyEInvoicing::query()->where('company_id', $submission->company_id)->first();
            $adapter = $settings === null || ! $settings->enabled ? null : $this->adapters->find((string) $settings->adapter_key);
            if ($settings === null || $adapter === null) {
                throw EInvoiceFailure::transient('adapter_unavailable', 'E-invoicing is switched off or its adapter is not available.');
            }
            $request = $this->builder->build(Invoice::query()->findOrFail($submission->invoice_id), $settings, $submission->document_version);

            $result = in_array($claim['was'], ['unknown', 'submitting'], true) ? $adapter->query($request->idempotencyKey) : null;
            $result ??= $adapter->submit($request);

            return $this->record($actor, $submissionId, accepted: $result);
        } catch (EInvoiceFailure $f) {
            return $this->record($actor, $submissionId, failure: $f);
        } catch (Throwable $e) {
            return $this->record($actor, $submissionId, failure: EInvoiceFailure::transient('adapter_error', mb_substr($e->getMessage(), 0, 500)));
        }
    }

    /** @return array{was: string}|null */
    private function claim(Actor $actor, string $id): ?array
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($id) {
            $s = EInvoiceSubmission::query()->whereKey($id)->lock('FOR UPDATE')->first();
            $now = $this->clock->now();
            if ($s === null || $s->dead_lettered_at !== null || ! in_array($s->status, ['pending', 'failed_retryable', 'unknown', 'submitting'], true)
                || ($s->next_attempt_at !== null && $s->next_attempt_at->getTimestamp() > $now->getTimestamp())) {
                return null;
            }

            if ($s->document_type === 'credit_note') {
                $doc = Invoice::query()->find($s->invoice_id);
                $original = $doc?->related_invoice_id === null ? null : EInvoiceSubmission::query()->where('invoice_id', $doc->related_invoice_id)->orderByDesc('document_version')->first();
                if ($original !== null && ! in_array($original->status, ['accepted', 'voided_by_credit'], true)) {
                    $s->forceFill([
                        'next_attempt_at' => $now->modify('+'.self::WAIT_FOR_ORIGINAL_SECONDS.' seconds')->format('Y-m-d H:i:sP'),
                        'last_error_code' => 'waiting_for_original', 'last_error_message' => 'Waiting for the invoice this credit note refers to to be accepted.',
                        'lock_version' => $s->lock_version + 1,
                    ])->save();

                    return null;
                }
            }

            $was = $s->status;
            $s->forceFill([
                'status' => 'submitting', 'attempts' => $s->attempts + 1,
                'next_attempt_at' => $now->modify('+'.self::LEASE_SECONDS.' seconds')->format('Y-m-d H:i:sP'), 'lock_version' => $s->lock_version + 1,
            ])->save();

            return ['was' => $was];
        });
    }

    private function record(Actor $actor, string $id, ?EInvoiceResult $accepted = null, ?EInvoiceFailure $failure = null): string
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($id, $accepted, $failure): string {
            $s = EInvoiceSubmission::query()->whereKey($id)->lock('FOR UPDATE')->firstOrFail();
            if ($s->status !== 'submitting') {
                return $s->status;
            }
            $now = $this->clock->now();
            $stamp = $now->format('Y-m-d H:i:sP');
            $before = ['status' => $s->status, 'attempts' => $s->attempts];

            if ($accepted !== null) {
                $s->forceFill([
                    'status' => 'accepted', 'next_attempt_at' => null, 'last_error_code' => null, 'last_error_message' => null,
                    'authority_number' => $accepted->authorityNumber, 'authority_signature' => $accepted->signature, 'qr_payload' => $accepted->qrPayload,
                    'authority_timestamp' => $accepted->authorityTimestamp, 'device_id' => $accepted->deviceId,
                    'raw_request' => $accepted->rawRequest, 'raw_response' => $accepted->rawResponse, 'accepted_at' => $stamp, 'lock_version' => $s->lock_version + 1,
                ])->save();
                $this->voidCreditedOriginal($s, $audit);
                $audit->add('einvoice.accepted', $s, before: $before, after: ['status' => 'accepted'], context: ['authority_number' => $accepted->authorityNumber]);

                return 'accepted';
            }

            /** @var EInvoiceFailure $failure */
            $common = ['last_error_code' => $failure->errorCode, 'last_error_message' => mb_substr($failure->getMessage(), 0, 1000), 'lock_version' => $s->lock_version + 1];
            if ($failure->rawRequest !== []) {
                $common['raw_request'] = $failure->rawRequest;
            }
            if ($failure->rawResponse !== []) {
                $common['raw_response'] = $failure->rawResponse;
            }
            if ($failure->kind === EInvoiceFailure::REJECTED) {
                $s->forceFill(['status' => 'rejected', 'next_attempt_at' => null, ...$common])->save();
                $audit->add('einvoice.rejected', $s, before: $before, after: ['status' => 'rejected'], context: ['code' => $failure->errorCode]);

                return 'rejected';
            }

            $status = $failure->kind === EInvoiceFailure::TIMEOUT ? 'unknown' : 'failed_retryable';
            $delay = EInvoiceRetryPolicy::delayAfter($s->attempts);
            $s->forceFill([
                'status' => $status, 'next_attempt_at' => $delay === null ? null : $now->modify("+{$delay} seconds")->format('Y-m-d H:i:sP'),
                'dead_lettered_at' => $delay === null ? $stamp : null, ...$common,
            ])->save();
            $audit->add($delay === null ? 'einvoice.dead_lettered' : 'einvoice.attempt_failed', $s, before: $before, after: ['status' => $status], context: ['code' => $failure->errorCode, 'attempts' => $s->attempts]);

            return $status;
        });
    }

    /** An accepted credit note for the whole of an accepted invoice voids that invoice at the authority (judgement: partial credits leave it accepted). */
    private function voidCreditedOriginal(EInvoiceSubmission $credit, AuditBuffer $audit): void
    {
        if ($credit->document_type !== 'credit_note') {
            return;
        }
        $note = Invoice::query()->find($credit->invoice_id);
        $original = $note?->related_invoice_id === null ? null : Invoice::query()->find($note->related_invoice_id);
        if ($note === null || $original === null || $note->total_fc !== $original->total_fc) {
            return;
        }
        $sub = EInvoiceSubmission::query()->where('invoice_id', $original->id)->where('status', 'accepted')->lock('FOR UPDATE')->first();
        if ($sub !== null) {
            $sub->forceFill(['status' => 'voided_by_credit', 'lock_version' => $sub->lock_version + 1])->save();
            $audit->add('einvoice.voided_by_credit', $sub, after: ['status' => 'voided_by_credit'], context: ['credit_note_id' => $note->id]);
        }
    }
}
