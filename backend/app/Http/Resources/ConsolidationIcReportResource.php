<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationIcMatch;
use App\Models\Consolidation\ConsolidationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The intercompany matching report of a run (D16.7): one row per pair of members and class, frozen with the run, and how
 * many differences above the group's tolerance still wait for a second person's acceptance.
 *
 * @mixin ConsolidationRun
 */
final class ConsolidationIcReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $matches = ConsolidationIcMatch::query()->where('run_id', $this->id)->orderBy('member_a_id')->orderBy('member_b_id')->orderBy('ic_class')->get();
        foreach ($matches as $m) {
            $m->setAttribute('display_currency', $this->currency);
        }
        // The tolerance the run was computed with (the group's may have changed since).
        $tolerance = (int) ($this->summary['ic_tolerance'] ?? 0);

        return [
            'run_id' => $this->id,
            'currency' => $this->currency,
            'ic_tolerance' => Json::money($tolerance, $this->currency),
            'awaiting_acceptance' => $matches->filter(fn (ConsolidationIcMatch $m): bool => $m->exceeds_tolerance && $m->accepted_by === null)->count(),
            'matches' => ConsolidationIcMatchResource::collection($matches),
        ];
    }
}
