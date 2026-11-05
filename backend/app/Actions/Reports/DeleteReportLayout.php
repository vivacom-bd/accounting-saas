<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Ledger\SetupGuard;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Reports\ReportLayout;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/** Removes a layout, and with it its schedule and the schedule's run history. Only the owner can (plan 3c.23). */
final class DeleteReportLayout
{
    public function __construct(private readonly UnitOfWork $uow, private readonly SetupGuard $guard) {}

    public function handle(Actor $actor, string $companyId, string $layoutId): void
    {
        $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $companyId, $layoutId): void {
            if ($actor->type !== ActorType::User) {
                throw DomainRuleViolation::single('forbidden', 'Layouts are removed by people.');
            }
            $this->guard->require($actor, 'reports.view', $companyId);
            $layout = ReportLayout::query()->where('company_id', $companyId)->whereKey($layoutId)->lockForUpdate()->first();
            if ($layout === null || ($layout->user_id !== $actor->id && $layout->visibility !== 'company')) {
                throw DomainRuleViolation::single('layout_not_found', 'That layout no longer exists.');
            }
            if ($layout->user_id !== $actor->id) {
                throw DomainRuleViolation::single('forbidden', 'Only the person who saved a layout can remove it.');
            }
            $audit->add('report_layout.deleted', $layout, $layout, null, ['report' => $layout->report_code]);
            $layout->delete();
        });
    }
}
