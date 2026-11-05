<?php

declare(strict_types=1);

namespace App\Models\Reports;

use App\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * A named set of a report's parameters (plan 3c.23). Always owned by one person; `visibility` is `private` or `company`
 * (everyone with access to the company can use it, only the owner can change it).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $user_id
 * @property string $report_code
 * @property string $name
 * @property string $visibility
 * @property array<string, mixed> $params
 * @property bool $is_default
 * @property int $lock_version
 * @property CarbonImmutable $updated_at
 */
class ReportLayout extends BaseModel
{
    protected $table = 'report_layouts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['params' => 'array', 'is_default' => 'boolean', 'lock_version' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
