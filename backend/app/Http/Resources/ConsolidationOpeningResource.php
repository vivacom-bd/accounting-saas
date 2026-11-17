<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationMemberOpening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Opening translated equity of a subsidiary held before the group's first run (D16.4). Amounts are net (debit minus
 * credit) in minor units of the presentation currency. `locked` is true while an approved run stands on it; it never
 * changes then, and unlocks only when that run is reversed.
 *
 * @mixin ConsolidationMemberOpening
 */
final class ConsolidationOpeningResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = (string) $this->resource->getAttribute('currency');
        $equity = [];
        foreach ($this->equity as $accountId => $amount) {
            $equity[] = ['group_account_id' => (string) $accountId, 'amount' => Json::money((int) $amount, $currency)];
        }

        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'as_of' => Json::date($this->as_of),
            'equity' => $equity,
            'translation_reserve' => Json::money($this->translation_reserve, $currency),
            'reason' => $this->reason,
            'locked' => $this->locked_by_run_id !== null,
            'locked_by_run_id' => $this->locked_by_run_id,
            'locked_at' => Json::time($this->locked_at),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'version' => $this->lock_version,
            'can' => Json::can($request, $this->resource, ['manage' => 'manage']),
        ];
    }
}
