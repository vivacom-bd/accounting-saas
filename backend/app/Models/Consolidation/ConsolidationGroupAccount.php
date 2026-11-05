<?php

declare(strict_types=1);

namespace App\Models\Consolidation;

use App\Models\BaseModel;

/**
 * @property string $id
 * @property string $group_id
 * @property string $code
 * @property string $name
 * @property string $account_type
 * @property string $account_subtype
 * @property string|null $role
 * @property list<string> $reporting_tags
 * @property bool $is_active
 */
class ConsolidationGroupAccount extends BaseModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reporting_tags' => 'array', 'is_active' => 'boolean'];
    }
}
