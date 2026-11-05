<?php

declare(strict_types=1);

namespace App\Models\EInvoicing;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * One document version sent, or to be sent, to the authority (plan 3c.27). `authority_number` is the authority's, apart from our number.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $invoice_id
 * @property int $document_version
 * @property string $idempotency_key
 * @property string $document_type
 * @property string $adapter_key
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $dead_lettered_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property string|null $authority_number
 * @property string|null $authority_signature
 * @property string|null $qr_payload
 * @property Carbon|null $authority_timestamp
 * @property string|null $device_id
 * @property array<string, mixed>|null $raw_request
 * @property array<string, mixed>|null $raw_response
 * @property Carbon|null $accepted_at
 * @property int $lock_version
 */
class EInvoiceSubmission extends BaseModel
{
    protected $table = 'einvoice_submissions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer', 'document_version' => 'integer', 'lock_version' => 'integer', 'next_attempt_at' => 'datetime', 'dead_lettered_at' => 'datetime',
            'authority_timestamp' => 'datetime', 'accepted_at' => 'datetime', 'raw_request' => 'array', 'raw_response' => 'array',
        ];
    }
}
