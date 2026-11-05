<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Transactions\WriteOff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A write-off as shown on the invoice it belongs to: its number, state, and how much of it has since been recovered (invoice currency).
 *
 * @mixin WriteOff
 */
final class InvoiceWriteOffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'write_off_date' => Json::date($this->write_off_date),
            'amount' => Json::money($this->amount_fc, $this->currency),
            'recovered' => Json::money($this->recovered_fc, $this->currency),
        ];
    }
}
