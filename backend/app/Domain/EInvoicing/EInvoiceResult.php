<?php

declare(strict_types=1);

namespace App\Domain\EInvoicing;

/**
 * What the authority answered for an accepted document (plan 3c.27). `authorityNumber` is the authority's own number for the document:
 * it is stored apart from our gapless number and never replaces it. `rawRequest` and `rawResponse` are kept for audit and support.
 */
final readonly class EInvoiceResult
{
    /**
     * @param  array<string, mixed>  $rawRequest
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $authorityNumber,
        public ?string $signature,
        public ?string $qrPayload,
        public ?string $authorityTimestamp,
        public ?string $deviceId,
        public array $rawRequest,
        public array $rawResponse,
    ) {}
}
