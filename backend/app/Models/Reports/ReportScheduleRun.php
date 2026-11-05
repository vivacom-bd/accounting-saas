<?php

declare(strict_types=1);

namespace App\Models\Reports;

use App\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * One due date of one schedule: the claim (unique per schedule and date) and the history entry.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $schedule_id
 * @property string $due_on
 * @property string $status queued | running | sent | failed | skipped
 * @property string|null $message
 * @property int $recipients
 * @property int|null $bytes
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $finished_at
 */
class ReportScheduleRun extends BaseModel
{
    protected $table = 'report_schedule_runs';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recipients' => 'integer', 'bytes' => 'integer', 'queued_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
