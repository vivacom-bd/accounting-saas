<?php

declare(strict_types=1);

namespace App\Services\EInvoicing;

use App\Domain\EInvoicing\EInvoiceRequest;
use App\Domain\Shared\DomainRuleViolation;
use App\Domain\Trade\InvoiceKind;
use App\Models\EInvoicing\CompanyEInvoicing;
use App\Models\EInvoicing\EInvoiceSubmission;
use App\Models\Tax\TaxCode;
use App\Models\Trade\Contact;
use App\Models\Trade\Invoice;
use App\Models\Trade\Item;

/**
 * Builds what an adapter is asked to send, from the stored (posted) document only (plan 3c.27). Country-neutral: it names the
 * document type, seller, buyer, lines, totals, currency and the original document; it knows nothing of any authority's format.
 * Amounts are the document's own, in its currency, as stored when it was priced and posted.
 */
final class EInvoiceRequestBuilder
{
    /** The e-invoicing document type of a stored document; null for kinds that are not sent (purchase bills and debit notes against them). */
    public static function documentTypeOf(InvoiceKind $kind): ?string
    {
        return match ($kind) {
            InvoiceKind::SalesInvoice => 'invoice',
            InvoiceKind::CreditNote => 'credit_note',
            default => null,
        };
    }

    public function build(Invoice $doc, CompanyEInvoicing $settings, int $version): EInvoiceRequest
    {
        $type = self::documentTypeOf($doc->kind) ?? throw DomainRuleViolation::single('einvoice_kind_not_sent', 'This kind of document is not sent to a tax authority.');
        $contact = Contact::query()->findOrFail($doc->contact_id);
        $lines = $doc->lines()->orderBy('line_no')->get();
        $items = Item::query()->whereIn('id', $lines->pluck('item_id')->filter()->all())->pluck('code', 'id');
        $codes = TaxCode::query()->whereIn('id', $lines->pluck('tax_code_id')->filter()->all())->pluck('code', 'id');

        $original = null;
        if ($doc->related_invoice_id !== null) {
            $parent = Invoice::query()->find($doc->related_invoice_id);
            if ($parent !== null) {
                $authority = EInvoiceSubmission::query()->where('invoice_id', $parent->id)->whereIn('status', ['accepted', 'voided_by_credit'])->orderByDesc('document_version')->value('authority_number');
                $original = ['number' => (string) $parent->number, 'authority_number' => is_string($authority) ? $authority : null];
            }
        }

        $rows = [];
        foreach ($lines as $l) {
            $rows[] = [
                'item_code' => $l->item_id !== null ? (string) ($items[$l->item_id] ?? $l->line_no) : (string) $l->line_no,
                'description' => (string) ($l->description ?? ''),
                'quantity' => (string) $l->quantity,
                'unit_price' => $l->unit_price_fc,
                'tax_class' => $l->tax_code_id === null ? null : ($codes[$l->tax_code_id] ?? null),
                'tax_amount' => $l->tax_amount_fc,
            ];
        }

        return new EInvoiceRequest(
            idempotencyKey: $doc->id.':'.$version,
            documentType: $type,
            documentNumber: (string) $doc->number,
            issueDate: $doc->issue_date->format('Y-m-d'),
            seller: ['tax_id' => (string) $settings->seller_tax_id, 'branch' => $settings->seller_branch],
            buyer: ['tax_id' => $contact->tax_id, 'name' => $contact->legal_name ?? $contact->name],
            lines: $rows,
            totals: ['net' => $doc->subtotal_fc, 'tax' => $doc->tax_total_fc, 'gross' => $doc->total_fc],
            currency: $doc->currency,
            original: $original,
        );
    }
}
