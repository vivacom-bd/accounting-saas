<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Ledger\SetupGuard;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Reports\ReportLayout;
use App\Models\Reports\ReportSchedule;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/** Stops a layout's scheduled delivery for good (its run history goes with it). Only the layout's owner (plan 3c.23). */
final class DeleteReportSchedule
{
    public function __construct(private readonly UnitOfWork $uow, private readonly SetupGuard $guard) {}

    public function handle(Actor $actor, string $companyId, string $layoutId): void
    {
        $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $layoutId): void {
            if ($actor->type !== ActorType::User) {
                throw DomainRuleViolation::single('forbidden', 'Schedules are removed by people.');
            }
            $this->guard->require($actor, 'reports.view', $companyId);
            $layout = ReportLayout::query()->where('company_id', $companyId)->whereKey($layoutId)->first();
            $schedule = $layout === null ? null : ReportSchedule::query()->where('layout_id', $layout->id)->lockForUpdate()->first();
            if ($layout === null || $schedule === null || $layout->user_id !== $actor->id) {
                throw DomainRuleViolation::single('schedule_not_found', 'There is no schedule on that layout.');
            }
            $audit->add('report_schedule.deleted', $schedule, $schedule, null, ['report' => $layout->report_code, 'layout_id' => $layout->id]);
            $schedule->delete();
        });
    }
}
