<?php

declare(strict_types=1);

namespace App\Services\Billing\Dto;

/** What an invoice would charge now. Amounts in integer minor units of `currency`. */
final readonly class GatewayInvoicePreview
{
    /** @param  list<array{description: string, amount: int}>  $lines */
    public function __construct(
        public string $currency,
        public int $amountDue,
        public array $lines,
    ) {}
}
