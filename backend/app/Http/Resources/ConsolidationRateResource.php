<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationRateOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An average-rate override: presentation-currency units per 1 unit of `currency` over the period.
 *
 * @mixin ConsolidationRateOverride
 */
final class ConsolidationRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'period_from' => Json::date($this->period_from),
            'period_to' => Json::date($this->period_to),
            'rate' => $this->rate,
            'reason' => $this->reason,
        ];
    }
}
