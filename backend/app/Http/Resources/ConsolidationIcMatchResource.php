<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Support\Json;
use App\Models\Consolidation\ConsolidationIcMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a run's intercompany matching report (D16.7), in the presentation currency after translation: what member A
 * and member B hold against each other in a class (net, debit positive), both eliminated in full, the difference left
 * between them and its likely cause. `matched` is the part of the two sides that mirrors. Above the group's tolerance the
 * difference blocks approval until a second person accepts it with a reason.
 *
 * @mixin ConsolidationIcMatch
 */
final class ConsolidationIcMatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = (string) $this->getAttribute('display_currency');
        $currencies = $this->detail['currencies'] ?? [];

        return [
            'id' => $this->id,
            'member_a_id' => $this->member_a_id,
            'member_b_id' => $this->member_b_id,
            /** balance | loan | income_expense | dividend */
            'ic_class' => $this->ic_class,
            'side_a' => Json::money($this->side_a, $currency),
            'side_b' => Json::money($this->side_b, $currency),
            /** The intercompany difference, posted to the group's intercompany differences account (G6910). */
            'difference' => Json::money($this->difference, $currency),
            /** A dividend's FX part (its income at the average instead of at the rate of its day), to the translation reserve. */
            'to_reserve' => Json::money($this->to_reserve, $currency),
            /** For a balance or loan, the difference earlier runs took to profit or loss; only the rest is this run's. */
            'recognised_before' => Json::money($this->recognised_before, $currency),
            'matched' => Json::money($this->matched, $currency),
            /** fx (the amounts agree in every transaction currency), timing (they do not), missing_counterpart, or null when matched */
            'cause' => $this->cause,
            /** @var array<string, int> both sides added per transaction currency, minor units of that currency */
            'transaction_currencies' => is_array($currencies) ? $currencies : [],
            'exceeds_tolerance' => $this->exceeds_tolerance,
            'accepted_by' => $this->accepted_by,
            'accepted_at' => Json::time($this->accepted_at),
            'reason' => $this->reason,
        ];
    }
}
