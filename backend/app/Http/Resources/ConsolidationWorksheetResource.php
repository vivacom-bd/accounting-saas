<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Consolidation\ConsolidationRun;
use App\Models\Consolidation\ConsolidationRunLine;
use App\Models\Consolidation\ConsolidationRunMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The frozen worksheet of a run (D16.3, D16.9): per member its window, currency and rates, and per group account each
 * member's figure in its own currency (`local`) and translated (`translated`), every adjustment with its source and entry,
 * and the consolidated total. Amounts are net, debit minus credit, so a credit is negative. The translated column of a
 * foreign member includes the translation reserve, its balancing line.
 *
 * @mixin ConsolidationRun
 */
final class ConsolidationWorksheetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $members = ConsolidationRunMember::query()->where('run_id', $this->id)->orderBy('id')->get();
        $currencies = $members->pluck('currency', 'member_id')->all();
        $lines = ConsolidationRunLine::query()->with('groupAccount')->where('run_id', $this->id)
            ->orderBy('kind')->orderBy('member_id')->orderBy('group_account_id')->get();
        foreach ($lines as $l) {
            $l->setAttribute('display_currency', $l->kind === 'local' && $l->member_id !== null ? ($currencies[$l->member_id] ?? $this->currency) : $this->currency);
        }

        return [
            'run_id' => $this->id,
            'currency' => $this->currency,
            'members' => ConsolidationRunMemberResource::collection($members),
            'lines' => ConsolidationRunLineResource::collection($lines),
        ];
    }
}
