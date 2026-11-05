<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Ledger\SetupGuard;
use App\Actions\Reports\Inputs\LayoutInput;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Reports\LayoutParams;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Organisation\Company;
use App\Models\Reports\ReportLayout;
use App\Queries\Reports\LayoutResolver;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use App\Support\UnitOfWork\VersionConflict;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Saves a report's chosen parameters under a name (plan 3c.23). Anyone who can view reports keeps private layouts; sharing one with
 * the company needs `reports.export`. Only the owner changes or removes a layout. Names are unique per owner and report; one layout
 * per owner and report can be the default (setting one clears the previous). A layout holds parameters, never figures, and
 * saving it writes nothing to the ledger. A period rule is tried against the company's calendar so a rule that cannot work is
 * refused now and not at the first scheduled run.
 */
final class SaveReportLayout
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly SetupGuard $guard,
        private readonly LayoutResolver $resolver,
    ) {}

    public function handle(Actor $actor, LayoutInput $in): ReportLayout
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $in): ReportLayout {
            if ($actor->type !== ActorType::User) {
                throw DomainRuleViolation::single('forbidden', 'Layouts are saved by people.');
            }
            $this->guard->require($actor, 'reports.view', $in->companyId);
            if ($in->visibility === 'company') {
                $this->guard->require($actor, 'reports.export', $in->companyId);
            }
            $name = trim($in->name);
            if ($name === '' || mb_strlen($name) > 80) {
                throw DomainRuleViolation::single('name_invalid', 'Give the layout a name of at most 80 characters.');
            }
            $params = LayoutParams::clean($in->report, $in->params);
            $company = Company::query()->findOrFail($in->companyId);
            $this->checkReferences($in->companyId, $params);
            $this->resolver->input($company, $in->report, $params, $this->clock->now()->setTimezone(new DateTimeZone($company->timezone)));

            $layout = $in->layoutId === null ? null : ReportLayout::query()->where('company_id', $in->companyId)->whereKey($in->layoutId)->lockForUpdate()->first();
            if ($in->layoutId !== null && ($layout === null || ! $this->visible($layout, $actor))) {
                throw DomainRuleViolation::single('layout_not_found', 'That layout no longer exists.');
            }
            if ($layout !== null && $layout->user_id !== $actor->id) {
                throw DomainRuleViolation::single('forbidden', 'Only the person who saved a layout can change it.');
            }
            if ($layout !== null && $layout->report_code !== $in->report) {
                throw DomainRuleViolation::single('layout_report_fixed', 'A layout belongs to one report.');
            }
            if ($layout !== null && $in->version !== null && $in->version !== $layout->lock_version) {
                throw new VersionConflict($layout->lock_version, $in->version);
            }
            $taken = ReportLayout::query()->where('company_id', $in->companyId)->where('user_id', $actor->id)->where('report_code', $in->report)
                ->where('name', $name)->when($layout !== null, fn ($q) => $q->where('id', '!=', $layout?->id))->exists();
            if ($taken) {
                throw DomainRuleViolation::single('layout_name_taken', 'You already have a layout with that name for this report.');
            }
            if ($in->isDefault) {
                ReportLayout::query()->where('company_id', $in->companyId)->where('user_id', $actor->id)->where('report_code', $in->report)->where('is_default', true)
                    ->when($layout !== null, fn ($q) => $q->where('id', '!=', $layout?->id))->update(['is_default' => false]);
            }

            $before = $layout?->attributesToArray();
            $layout ??= (new ReportLayout)->forceFill(['id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $in->companyId, 'user_id' => $actor->id, 'report_code' => $in->report]);
            $layout->forceFill(['name' => $name, 'visibility' => $in->visibility, 'params' => $params, 'is_default' => $in->isDefault, 'lock_version' => $layout->exists ? $layout->lock_version + 1 : 0])->save();
            $layout->refresh();
            $audit->add($before === null ? 'report_layout.saved' : 'report_layout.updated', $layout, $before, $layout, ['report' => $in->report]);

            return $layout;
        });
    }

    /** A layout is seen by its owner and, when shared, by everyone in the company. */
    private function visible(ReportLayout $layout, Actor $actor): bool
    {
        return $layout->user_id === $actor->id || $layout->visibility === 'company';
    }

    /** @param array<string, mixed> $params */
    private function checkReferences(string $companyId, array $params): void
    {
        $tables = ['period_id' => 'fiscal_periods', 'account_id' => 'accounts', 'contact_id' => 'contacts', 'budget_id' => 'budgets', 'breakdown' => 'dimensions'];
        foreach ($tables as $key => $table) {
            if (isset($params[$key]) && ! DB::table($table)->where('company_id', $companyId)->where('id', (string) $params[$key])->exists()) {
                throw DomainRuleViolation::single('layout_reference_missing', 'Something this layout refers to does not exist in this company.');
            }
        }
    }
}
