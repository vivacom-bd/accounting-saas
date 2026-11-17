<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Consolidation\ConsolidationIcContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member company's contact that stands for another member (D16.7).
 *
 * @mixin ConsolidationIcContact
 */
final class ConsolidationIcContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'contact_id' => $this->contact_id,
            'counterparty_member_id' => $this->counterparty_member_id,
        ];
    }
}
