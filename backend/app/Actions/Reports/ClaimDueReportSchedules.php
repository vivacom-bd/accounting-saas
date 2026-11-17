<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Reports\ScheduleRule;
use App\Models\Organisation\Company;
use App\Models\Reports\ReportSchedule;
use App\Models\Reports\ReportScheduleRun;
use App\Support\Actor;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The scheduler's half of scheduled reports (plan 3c.23), for one company, as the system. A schedule is due on a date in the
 * company's own timezone once its hour has come. Taking the date is one insert into `report_schedule_runs`, unique per (schedule,
 * due date): two scheduler passes at once, or a pass repeated after a crash, take it once, and the loser gets nothing back. Runs
 * that were taken but never finished (a worker died) are closed as failed after `reports.schedule_stuck_minutes`, which the
 * owner is told about by the caller. Schedules that are paused are not touched.
 */
final class ClaimDueReportSchedules
{
    public function __construct(private readonly Clock $clock, private readonly IdGenerator $ids) {}

    /**
     * @return array{claimed: list<string>, stuck: list<ReportScheduleRun>} run ids to deliver; runs closed as failed
     */
    public function handle(Actor $system, Company $company, ?DateTimeImmutable $at = null): array
    {
        $now = $at ?? $this->clock->now();
        $local = $now->setTimezone(new DateTimeZone($company->timezone));
        $claimed = [];
        foreach (ReportSchedule::query()->where('company_id', $company->id)->where('active', true)->orderBy('id')->get() as $schedule) {
            $due = ScheduleRule::dueOn($schedule->frequency, $schedule->weekday, $schedule->month_day, $schedule->last_day, $schedule->hour, $local);
            if ($due === null) {
                continue;
            }
            $id = $this->ids->next();
            $inserted = ReportScheduleRun::query()->insertOrIgnore([
                'id' => $id, 'tenant_id' => $system->tenantId, 'company_id' => $company->id, 'schedule_id' => $schedule->id,
                'due_on' => $due, 'status' => 'queued', 'recipients' => 0, 'queued_at' => $now->format('Y-m-d H:i:sP'),
            ]);
            if ($inserted === 1) {
                $claimed[] = $id;
            }
        }

        $cutoff = $now->modify('-'.(int) config('reports.schedule_stuck_minutes').' minutes')->format('Y-m-d H:i:sP');
        $stuck = ReportScheduleRun::query()->where('company_id', $company->id)->whereIn('status', ['queued', 'running'])->where('queued_at', '<', $cutoff)->orderBy('id')->get();
        foreach ($stuck as $run) {
            $run->forceFill(['status' => 'failed', 'finished_at' => $now->format('Y-m-d H:i:sP'), 'message' => 'The report never finished, so nothing was sent for this date. The next run goes ahead as scheduled.'])->save();
            ReportSchedule::query()->whereKey($run->schedule_id)->update(['last_run_at' => $now->format('Y-m-d H:i:sP'), 'last_status' => 'failed']);
        }

        return ['claimed' => $claimed, 'stuck' => array_values($stuck->all())];
    }
}
