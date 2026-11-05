<?php

declare(strict_types=1);

namespace App\Domain\EInvoicing;

/**
 * Where a document stands with the tax authority's e-invoicing system (plan 3c.27). `not_required` is not stored: a document with no
 * submission is not required, because e-invoicing is off for the company or the document type does not need it.
 *
 * - `pending`: queued, not tried yet.
 * - `submitting`: an attempt is in flight (or died; after its lease it is treated as `unknown`).
 * - `accepted`: the authority issued its own document number and signature.
 * - `rejected`: the authority refused it. Terminal: the same document is never resent. Fix it by a credit note and a new invoice.
 * - `failed_retryable`: the attempt failed in a way worth repeating (network, authority down). Retried on a back-off, then dead-lettered.
 * - `unknown`: no answer (timeout). The authority may have it. Always query before sending again.
 * - `voided_by_credit`: accepted, then cancelled in full by an accepted credit note.
 */
enum EInvoiceStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Submitting = 'submitting';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case FailedRetryable = 'failed_retryable';
    case Unknown = 'unknown';
    case VoidedByCredit = 'voided_by_credit';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::VoidedByCredit], true);
    }

    /** Whether a person may ask for another attempt. */
    public function canRetry(): bool
    {
        return in_array($this, [self::FailedRetryable, self::Unknown], true);
    }
}
