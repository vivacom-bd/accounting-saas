<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry of the consolidation ledger. It has a number only once posted. Lines are in the group's presentation currency
 * (debit and credit in minor units).
 *
 * @mixin ConsolidationEntry
 */
final class ConsolidationEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = $this->group->presentation_currency;

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'run_id' => $this->run_id,
            'number' => $this->number,
            'status' => $this->status,
            'kind' => $this->kind,
            'entry_date' => Json::date($this->entry_date),
            'member_id' => $this->member_id,
            'memo' => $this->memo,
            'total' => Json::money($this->total_debit, $currency),
            'reverses_entry_id' => $this->reverses_entry_id,
            'reversed_by_id' => $this->reversed_by_id,
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'posted_at' => Json::time($this->posted_at),
            'version' => $this->lock_version,
            'lines' => ConsolidationEntryLineResource::collection($this->whenLoaded('lines')),
            'can' => Json::can($request, $this->resource, ['approve' => 'approve', 'manage' => 'manage']),
        ];
    }
}
