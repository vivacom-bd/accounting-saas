<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\EInvoicing\EInvoiceFailure;
use App\Domain\EInvoicing\EInvoiceRequest;
use App\Domain\EInvoicing\EInvoiceResult;

/**
 * Talks to one tax authority's e-invoicing system (plan 3c.27). It is never a posting dependency: the document is already posted and
 * numbered when an adapter is called, from a queued job. An adapter holds no state of ours; it must not touch the database.
 *
 * `submit` returns the authority's answer or throws `EInvoiceFailure` (`rejected`, `transient`, `timeout`). After a `timeout` the
 * caller asks `query` before sending again, so an adapter should make `query` find a document by the request's idempotency key.
 */
interface EInvoiceAdapter
{
    /** The key a company stores to choose this adapter. */
    public function key(): string;

    public function label(): string;

    /** True only for adapters that exist for tests. They are refused outside the testing environment. */
    public function isTestOnly(): bool;

    /** Whether credentials and endpoints are set, so the adapter can be used. A company can turn e-invoicing on only with a configured adapter. */
    public function isConfigured(): bool;

    /** @throws EInvoiceFailure */
    public function submit(EInvoiceRequest $request): EInvoiceResult;

    /**
     * The authority's record of a document it may have accepted, found by idempotency key; null when it has none.
     *
     * @throws EInvoiceFailure
     */
    public function query(string $idempotencyKey): ?EInvoiceResult;
}
