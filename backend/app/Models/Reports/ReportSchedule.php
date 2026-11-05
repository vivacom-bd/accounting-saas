<?php

declare(strict_types=1);

namespace App\Models\Reports;

use App\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * Scheduled email delivery of one layout (plan 3c.23). Runs as `user_id`, the layout's owner.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $layout_id
 * @property string $user_id
 * @property string $frequency
 * @property int|null $weekday
 * @property int|null $month_day
 * @property bool $last_day
 * @property int $hour
 * @property string $format
 * @property list<string> $recipient_user_ids
 * @property list<string> $recipient_emails
 * @property bool $active
 * @property string|null $paused_reason
 * @property int $lock_version
 * @property CarbonImmutable|null $last_run_at
 * @property string|null $last_status
 */
class ReportSchedule extends BaseModel
{
    protected $table = 'report_schedules';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer', 'month_day' => 'integer', 'last_day' => 'boolean', 'hour' => 'integer', 'active' => 'boolean',
            'recipient_user_ids' => 'array', 'recipient_emails' => 'array', 'lock_version' => 'integer', 'last_run_at' => 'immutable_datetime',
        ];
    }
}
