<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Transactions\WriteOffRecovery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recovery after write-off. `amount` is in the invoice's currency, `base_amount` the base carried at the write-off's rate. The
 * money itself arrives through a normal receipt allocated to the reopened invoice.
 *
 * @mixin WriteOffRecovery
 */
final class WriteOffRecoveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $base = Json::baseCurrency($request);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'write_off_id' => $this->write_off_id,
            'invoice_id' => $this->invoice_id,
            'contact_id' => $this->contact_id,
            'recovery_date' => Json::date($this->recovery_date),
            'amount' => Json::money($this->amount_fc, $this->currency),
            'base_amount' => Json::money($this->amount, $base),
            'reason' => $this->reason,
            'reference' => $this->reference,
            'memo' => $this->memo,
            'journal_id' => $this->journal_id,
            'reversal_journal_id' => $this->reversal_journal_id,
            'created_by' => $this->created_by,
            'submitted_by' => $this->submitted_by,
            'approved_by' => $this->approved_by,
            'posted_by' => $this->posted_by,
            'posted_at' => Json::time($this->posted_at),
            'version' => $this->lock_version,
            'can' => Json::can($request, $this->resource, [
                'update' => 'update', 'submit' => 'submit', 'approve' => 'approve', 'return' => 'return', 'void' => 'void', 'reverse' => 'reverse',
            ]),
        ];
    }
}
