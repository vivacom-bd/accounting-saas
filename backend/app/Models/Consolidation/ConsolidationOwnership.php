<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * @property string $id
 * @property string $group_id
 * @property string $member_id
 * @property DateTimeImmutable $effective_on
 * @property int $percent_bp the parent's share, 10000 = 100%
 * @property string|null $note
 */
class ConsolidationOwnership extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'percent_bp' => 'integer'];
    }
}
