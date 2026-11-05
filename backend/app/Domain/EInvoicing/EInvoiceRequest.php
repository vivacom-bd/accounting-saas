<?php

declare(strict_types=1);

namespace App\Domain\EInvoicing;

/**
 * What an adapter is asked to submit (plan 3c.27), country-neutral. Money is integer minor units of `$currency`; quantities are
 * decimal strings. `$idempotencyKey` is "<document id>:<document version>": an adapter that can must pass it on so a resend is safe.
 *
 * @phpstan-type Line array{item_code: string, description: string, quantity: string, unit_price: int, tax_class: string|null, tax_amount: int}
 */
final readonly class EInvoiceRequest
{
    /**
     * @param  string  $documentType  invoice | credit_note | debit_note
     * @param  array{tax_id: string, branch: string|null}  $seller
     * @param  array{tax_id: string|null, name: string}  $buyer
     * @param  list<array{item_code: string, description: string, quantity: string, unit_price: int, tax_class: string|null, tax_amount: int}>  $lines
     * @param  array{net: int, tax: int, gross: int}  $totals
     * @param  array{number: string, authority_number: string|null}|null  $original  the document a credit or debit note refers to
     */
    public function __construct(
        public string $idempotencyKey,
        public string $documentType,
        public string $documentNumber,
        public string $issueDate,
        public array $seller,
        public array $buyer,
        public array $lines,
        public array $totals,
        public string $currency,
        public ?array $original = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey, 'document_type' => $this->documentType, 'document_number' => $this->documentNumber,
            'issue_date' => $this->issueDate, 'seller' => $this->seller, 'buyer' => $this->buyer, 'lines' => $this->lines,
            'totals' => $this->totals, 'currency' => $this->currency, 'original' => $this->original,
        ];
    }
}
