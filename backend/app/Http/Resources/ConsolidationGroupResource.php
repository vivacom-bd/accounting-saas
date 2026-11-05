<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A consolidation group with its members. `ic_tolerance` is in minor units of the presentation currency. A member's
 * `ownership` is the dated history of the parent's share in basis points (10000 = 100%).
 *
 * @mixin ConsolidationGroup
 */
final class ConsolidationGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'presentation_currency' => $this->presentation_currency,
            'parent_company_id' => $this->parent_company_id,
            'ic_tolerance' => Json::money($this->ic_tolerance, $this->presentation_currency),
            'status' => $this->status,
            'version' => $this->lock_version,
            'members' => ConsolidationMemberResource::collection($this->whenLoaded('members')),
            'can' => Json::can($request, $this->resource, ['manage' => 'manage', 'run' => 'run', 'approve' => 'approve']),
        ];
    }
}
