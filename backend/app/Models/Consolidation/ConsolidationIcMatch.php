<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * One row of a run's intercompany matching report (D16.7): a pair of members and a class, in the presentation currency.
 * Frozen with the run, except that a difference above tolerance can be accepted once, with a reason, by a second person.
 *
 * @property string $id
 * @property string $run_id
 * @property string $member_a_id
 * @property string $member_b_id
 * @property string $ic_class
 * @property int $side_a
 * @property int $side_b
 * @property int $difference the intercompany difference, to the intercompany differences account
 * @property int $to_reserve the FX part of a dividend, to the translation reserve
 * @property int $recognised_before a balance or loan difference earlier runs took to profit or loss
 * @property int $matched
 * @property string|null $cause fx|timing|missing_counterpart
 * @property bool $exceeds_tolerance
 * @property array<string, mixed> $detail
 * @property string|null $accepted_by
 * @property Carbon|null $accepted_at
 * @property string|null $reason
 */
class ConsolidationIcMatch extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'side_a' => 'integer', 'side_b' => 'integer', 'difference' => 'integer', 'to_reserve' => 'integer', 'recognised_before' => 'integer', 'matched' => 'integer',
            'exceeds_tolerance' => 'boolean', 'detail' => 'array', 'accepted_at' => 'datetime',
        ];
    }
}
