<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationRunMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member as a run read it: its window, currency, the rates it was translated at and the ledger version of its books then.
 *
 * @mixin ConsolidationRunMember
 */
final class ConsolidationRunMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'member_id' => $this->member_id,
            'company_id' => $this->company_id,
            'currency' => $this->currency,
            'window_from' => Json::date($this->window_from),
            'ledger_version' => $this->ledger_version,
            /** Presentation-currency units per 1 unit of `currency`; null for a member in the presentation currency. */
            'closing_rate' => $this->closing_rate,
            'average_rate' => $this->average_rate,
            /** How each rate was found: the closing rate's date; the average's method (`daily_mean` or `override`, with the reason). */
            'rate_detail' => (object) $this->rate_detail,
        ];
    }
}
