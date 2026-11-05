<?php

declare(strict_types=1);

namespace App\Support\Testing;

use App\Contracts\EInvoiceAdapter;
use App\Domain\EInvoicing\EInvoiceFailure;
use App\Domain\EInvoicing\EInvoiceRequest;
use App\Domain\EInvoicing\EInvoiceResult;

/**
 * An adapter for tests only (plan 3c.27). It talks to nobody and says so: `isTestOnly()` is true, so the registry refuses it outside
 * the testing environment, and a company cannot turn e-invoicing on with it in production. It is NOT an authority and its numbers are
 * not real. Script what the next calls do with `willFail()`, `willAccept()`; calls and accepted documents are recorded.
 */
final class FakeEInvoiceAdapter implements EInvoiceAdapter
{
    /** @var list<EInvoiceFailure|null> outcomes for the next submits, in order; null accepts; empty accepts */
    private array $script = [];

    /** @var list<EInvoiceRequest> */
    public array $submitted = [];

    /** @var list<string> */
    public array $queried = [];

    /** @var array<string, EInvoiceResult> accepted documents by idempotency key (what `query` finds) */
    public array $accepted = [];

    private int $counter = 0;

    public bool $down = false;

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake adapter (tests only)';
    }

    public function isTestOnly(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function willFail(EInvoiceFailure ...$failures): self
    {
        foreach ($failures as $f) {
            $this->script[] = $f;
        }

        return $this;
    }

    public function willAccept(int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->script[] = null;
        }

        return $this;
    }

    /** Accept the document but lose the answer: the authority has it, the caller sees a timeout. */
    public function willTimeoutAfterAccepting(): self
    {
        $this->script[] = EInvoiceFailure::timeout('Lost the answer.', ['after_accept' => true]);

        return $this;
    }

    public function submit(EInvoiceRequest $request): EInvoiceResult
    {
        $this->submitted[] = $request;
        if ($this->down) {
            throw EInvoiceFailure::transient('authority_down', 'The authority is not reachable.', $request->toArray());
        }
        $next = $this->script === [] ? null : array_shift($this->script);
        if ($next instanceof EInvoiceFailure) {
            if ($next->kind === EInvoiceFailure::TIMEOUT && ($next->rawRequest['after_accept'] ?? false) === true) {
                $this->accepted[$request->idempotencyKey] = $this->result($request);
            }
            throw $next;
        }

        // A resend of a document the authority already holds returns the same answer.
        return $this->accepted[$request->idempotencyKey] ??= $this->result($request);
    }

    public function query(string $idempotencyKey): ?EInvoiceResult
    {
        $this->queried[] = $idempotencyKey;
        if ($this->down) {
            throw EInvoiceFailure::transient('authority_down', 'The authority is not reachable.');
        }

        return $this->accepted[$idempotencyKey] ?? null;
    }

    private function result(EInvoiceRequest $request): EInvoiceResult
    {
        $n = ++$this->counter;

        return new EInvoiceResult(
            authorityNumber: sprintf('FAKE-%06d', $n), signature: 'fake-signature-'.$n, qrPayload: 'https://example.invalid/fake/'.$n,
            authorityTimestamp: '2026-03-15T10:00:00Z', deviceId: 'fake-device', rawRequest: $request->toArray(), rawResponse: ['fake' => true, 'n' => $n],
        );
    }
}
