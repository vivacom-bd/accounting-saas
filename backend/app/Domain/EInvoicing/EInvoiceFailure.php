<?php

declare(strict_types=1);

namespace App\Domain\EInvoicing;

use RuntimeException;

/**
 * An adapter failed to get a document accepted. Three kinds, because each is handled differently (plan 3c.27):
 * `rejected` (the authority refused: terminal), `transient` (try again later) and `timeout` (no answer: status unknown, query first).
 */
final class EInvoiceFailure extends RuntimeException
{
    public const string REJECTED = 'rejected';

    public const string TRANSIENT = 'transient';

    public const string TIMEOUT = 'timeout';

    /** @param  array<string, mixed>  $rawRequest
     * @param  array<string, mixed>  $rawResponse */
    private function __construct(
        public readonly string $kind,
        public readonly string $errorCode,
        string $message,
        public readonly array $rawRequest = [],
        public readonly array $rawResponse = [],
    ) {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>  $rawRequest
     * @param  array<string, mixed>  $rawResponse */
    public static function rejected(string $code, string $message, array $rawRequest = [], array $rawResponse = []): self
    {
        return new self(self::REJECTED, $code, $message, $rawRequest, $rawResponse);
    }

    /** @param  array<string, mixed>  $rawRequest
     * @param  array<string, mixed>  $rawResponse */
    public static function transient(string $code, string $message, array $rawRequest = [], array $rawResponse = []): self
    {
        return new self(self::TRANSIENT, $code, $message, $rawRequest, $rawResponse);
    }

    /** @param  array<string, mixed>  $rawRequest */
    public static function timeout(string $message = 'The authority did not answer in time.', array $rawRequest = []): self
    {
        return new self(self::TIMEOUT, 'timeout', $message, $rawRequest);
    }
}
