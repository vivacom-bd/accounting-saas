<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationRunLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cell of a run's worksheet. `amount` is net (debit minus credit), in the member's own currency for a `local` line and
 * in the run's currency otherwise.
 *
 * @mixin ConsolidationRunLine
 */
final class ConsolidationRunLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** local | translated | adjustment | consolidated */
            'kind' => $this->kind,
            'member_id' => $this->member_id,
            'group_account_id' => $this->group_account_id,
            'code' => $this->groupAccount?->code,
            'name' => $this->groupAccount?->name,
            /** Where an adjustment came from, such as `entry` (a posted consolidation entry, see `entry_id`). */
            'source' => $this->source,
            'entry_id' => $this->entry_id,
            'amount' => Json::money($this->amount, (string) $this->resource->getAttribute('display_currency')),
        ];
    }
}
