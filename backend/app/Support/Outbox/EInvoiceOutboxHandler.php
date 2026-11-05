<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Contracts\OutboxHandler;
use App\Domain\Trade\InvoiceKind;
use App\Models\EInvoicing\CompanyEInvoicing;
use App\Services\EInvoicing\EInvoiceQueue;
use App\Services\EInvoicing\EInvoiceRequestBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * A sales invoice or credit note was posted (plan 3c.27): when the company has e-invoicing on, queue its submission. This runs from the
 * outbox AFTER the posting committed, so a posting never depends on an authority. Idempotent: the submission is unique per document and
 * version, so a redelivered message queues nothing new. A company with e-invoicing off, or a purchase document, gets no row at all
 * (its status is `not_required`).
 */
final class EInvoiceOutboxHandler implements OutboxHandler
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $tenant,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly EInvoiceQueue $queue,
    ) {}

    public function handle(OutboxMessage $message): void
    {
        if ($this->tenant->require() !== $message->tenantId) {
            throw new LogicException('The outbox message does not belong to the current tenant.');
        }
        $kind = InvoiceKind::tryFrom((string) ($message->payload['kind'] ?? ''));
        $type = $kind === null ? null : EInvoiceRequestBuilder::documentTypeOf($kind);
        if ($type === null) {
            return;
        }
        $companyId = (string) $message->payload['company_id'];
        $documentId = (string) $message->payload['document_id'];
        $version = (int) $message->payload['version'];

        $settings = CompanyEInvoicing::query()->where('company_id', $companyId)->where('enabled', true)->first();
        if ($settings === null || $settings->adapter_key === null) {
            return;
        }

        $id = $this->ids->next();
        $now = $this->clock->now()->format('Y-m-d H:i:sP');
        $inserted = $this->db->connection()->affectingStatement(
            'INSERT INTO einvoice_submissions (id, tenant_id, company_id, invoice_id, document_version, idempotency_key, document_type, adapter_key, status, attempts, next_attempt_at, lock_version, created_at, updated_at) '
            ."VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, 0, ?, ?) ON CONFLICT DO NOTHING",
            [$id, $message->tenantId, $companyId, $documentId, $version, $documentId.':'.$version, $type, $settings->adapter_key, $now, $now, $now],
        );
        if ($inserted > 0) {
            $this->queue->push($message->tenantId, $id);
        }
    }
}
