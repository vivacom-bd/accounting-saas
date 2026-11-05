<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A group member. `ownership` is the dated history of the parent's share in basis points (10000 = 100%); a row applies to results after its date.
 *
 * @mixin ConsolidationMember
 */
final class ConsolidationMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'company_name' => $this->company?->name,
            'role' => $this->role,
            'acquisition_date' => Json::date($this->acquisition_date),
            'status' => $this->status,
            'ownership' => $this->ownerships->map(fn ($o): array => ['effective_on' => Json::date($o->effective_on), 'percent_bp' => $o->percent_bp, 'note' => $o->note])->all(),
        ];
    }
}
