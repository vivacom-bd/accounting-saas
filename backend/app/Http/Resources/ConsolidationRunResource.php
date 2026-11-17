<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationRun;
use App\Services\Consolidation\RunStaleness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A consolidation run. `stale` is true when a member's books moved after the run read them; a stale run cannot be
 * approved and must be voided and computed again. Warnings never block (for example a member period that is not closed).
 * Amounts in `summary` are minor units of the run's currency.
 *
 * @mixin ConsolidationRun
 */
final class ConsolidationRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summary = $this->summary;

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'period_from' => Json::date($this->period_from),
            'period_to' => Json::date($this->period_to),
            'currency' => $this->currency,
            'status' => $this->status,
            'stale' => app(RunStaleness::class)->isStale($this->resource) === true,
            'warnings' => array_map(fn (array $w): array => [
                'code' => (string) ($w['code'] ?? ''), 'message' => (string) ($w['message'] ?? ''), 'member_id' => isset($w['member_id']) ? (string) $w['member_id'] : null,
            ], $this->warnings),
            'profit' => Json::money((int) ($summary['profit'] ?? 0), $this->currency),
            /** The period's movement of the translation reserve (other comprehensive income); a credit is negative. */
            'translation_difference' => Json::money((int) ($summary['translation_difference'] ?? 0), $this->currency),
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => Json::time($this->approved_at),
            'reversed_at' => Json::time($this->reversed_at),
            'reason' => $this->reason,
            'version' => $this->lock_version,
            'can' => Json::can($request, $this->resource, ['approve' => 'approve', 'run' => 'run']),
        ];
    }
}
