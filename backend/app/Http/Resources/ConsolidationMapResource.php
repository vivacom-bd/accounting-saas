<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Consolidation\ConsolidationAccountMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mapping from a member account to a group account.
 *
 * @mixin ConsolidationAccountMap
 */
final class ConsolidationMapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'account_id' => $this->account_id,
            'group_account_id' => $this->group_account_id,
        ];
    }
}
