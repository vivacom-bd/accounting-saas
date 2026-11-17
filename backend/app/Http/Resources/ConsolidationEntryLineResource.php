<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Consolidation\ConsolidationEntryLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A line of a consolidation entry, in minor units of the presentation currency.
 *
 * @mixin ConsolidationEntryLine
 */
final class ConsolidationEntryLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'group_account_id' => $this->group_account_id,
            'member_id' => $this->member_id,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
        ];
    }
}
