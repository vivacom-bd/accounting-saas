<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Ledger\SetupGuard;
use App\Actions\Reports\Inputs\ScheduleInput;
use App\Contracts\IdGenerator;
use App\Domain\Reports\ScheduleRule;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Reports\ReportLayout;
use App\Models\Reports\ReportSchedule;
use App\Queries\Reports\ScheduleRecipients;
use App\Services\Reports\ReportAccess;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use App\Support\UnitOfWork\VersionConflict;

/**
 * Sets, changes, pauses or resumes the scheduled email delivery of a layout (plan 3c.23; needs `reports.schedule` and the right to
 * export that report). Only the layout's owner schedules it, and the schedule runs as them. A scheduled report moves with the
 * calendar, so a layout fixed to one period is refused. Recipients are people of the company (by id) or outside addresses; at most
 * `reports.schedule_max_recipients`. Saving also resumes a schedule the system paused, once the owner has the rights again.
 */
final class SaveReportSchedule
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly SetupGuard $guard,
        private readonly ReportAccess $access,
        private readonly ScheduleRecipients $people,
    ) {}

    public function handle(Actor $actor, ScheduleInput $in): ReportSchedule
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in): ReportSchedule {
            if ($actor->type !== ActorType::User) {
                throw DomainRuleViolation::single('forbidden', 'Schedules are set up by people.');
            }
            $this->guard->require($actor, 'reports.schedule', $in->companyId);
            $layout = ReportLayout::query()->where('company_id', $in->companyId)->whereKey($in->layoutId)->lockForUpdate()->first();
            if ($layout === null || $layout->user_id !== $actor->id) {
                throw DomainRuleViolation::single('layout_not_found', 'Only the person who saved a layout can schedule it.');
            }
            $refusal = $this->access->refusal($actor, $layout->report_code, $in->companyId);
            if ($refusal !== null) {
                throw DomainRuleViolation::single('forbidden', $refusal);
            }
            $this->assertRunnable($layout);
            $this->assertTiming($in);
            [$userIds, $emails] = $this->recipients($in);

            $schedule = ReportSchedule::query()->where('layout_id', $layout->id)->lockForUpdate()->first();
            if ($schedule !== null && $in->version !== null && $in->version !== $schedule->lock_version) {
                throw new VersionConflict($schedule->lock_version, $in->version);
            }
            $before = $schedule?->attributesToArray();
            $schedule ??= (new ReportSchedule)->forceFill(['id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $in->companyId, 'layout_id' => $layout->id, 'user_id' => $actor->id]);
            $schedule->forceFill([
                'frequency' => $in->frequency, 'weekday' => $in->frequency === 'weekly' ? $in->weekday : null,
                'month_day' => in_array($in->frequency, ['monthly', 'quarterly'], true) && ! $in->lastDay ? $in->monthDay : null,
                'last_day' => in_array($in->frequency, ['monthly', 'quarterly'], true) && $in->lastDay,
                'hour' => $in->hour, 'format' => $in->format, 'recipient_user_ids' => $userIds, 'recipient_emails' => $emails,
                'active' => $in->active, 'paused_reason' => $in->active ? null : 'paused_by_owner',
                'lock_version' => $schedule->exists ? $schedule->lock_version + 1 : 0,
            ])->save();
            $schedule->refresh();
            $audit->add($before === null ? 'report_schedule.saved' : 'report_schedule.updated', $schedule, $before, $schedule, ['report' => $layout->report_code, 'layout_id' => $layout->id]);

            return $schedule;
        });
    }

    private function assertRunnable(ReportLayout $layout): void
    {
        $params = $layout->params;
        if (! isset($params['rule'])) {
            throw DomainRuleViolation::single('schedule_needs_rule', 'A scheduled report has to move with the calendar. Save the layout with a period choice such as "Last month", not one fixed period.');
        }
        if ($layout->report_code === 'general-ledger' && ! isset($params['account_id'])) {
            throw DomainRuleViolation::single('schedule_needs_account', 'Choose the account in the layout before scheduling the general ledger.');
        }
        if (in_array($layout->report_code, ['customer-statement', 'supplier-statement'], true) && ! isset($params['contact_id'])) {
            throw DomainRuleViolation::single('schedule_needs_contact', 'Choose the customer or supplier in the layout before scheduling a statement.');
        }
    }

    private function assertTiming(ScheduleInput $in): void
    {
        if (! in_array($in->frequency, ScheduleRule::FREQUENCIES, true) || ! in_array($in->format, ['pdf', 'xlsx', 'csv'], true)) {
            throw DomainRuleViolation::single('schedule_invalid', 'Choose how often and in which format.');
        }
        if ($in->frequency === 'weekly' && ($in->weekday === null || $in->weekday < 1 || $in->weekday > 7)) {
            throw DomainRuleViolation::single('schedule_invalid', 'Choose the day of the week.');
        }
        if (in_array($in->frequency, ['monthly', 'quarterly'], true) && ! $in->lastDay && ($in->monthDay === null || $in->monthDay < 1 || $in->monthDay > 28)) {
            throw DomainRuleViolation::single('schedule_invalid', 'Choose a day between 1 and 28, or the last day of the month.');
        }
        if ($in->hour < 0 || $in->hour > 23) {
            throw DomainRuleViolation::single('schedule_invalid', 'Choose an hour of the day.');
        }
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function recipients(ScheduleInput $in): array
    {
        $members = array_column(($this->people)($in->companyId), 'id');
        $userIds = [];
        foreach ($in->userIds as $id) {
            if (! in_array($id, $members, true)) {
                throw DomainRuleViolation::single('recipient_invalid', 'One of the people chosen does not have access to this company.');
            }
            $userIds[$id] = $id;
        }
        $emails = [];
        foreach ($in->emails as $address) {
            $address = strtolower(trim($address));
            if ($address === '') {
                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || strlen($address) > 254) {
                throw DomainRuleViolation::single('recipient_invalid', "{$address} is not a valid email address.");
            }
            $emails[$address] = $address;
        }
        $count = count($userIds) + count($emails);
        if ($count === 0) {
            throw DomainRuleViolation::single('recipient_required', 'Add at least one person or email address to send to.');
        }
        $max = (int) config('reports.schedule_max_recipients');
        if ($count > $max) {
            throw DomainRuleViolation::single('too_many_recipients', "Send a scheduled report to at most {$max} recipients.");
        }

        return [array_values($userIds), array_values($emails)];
    }
}
