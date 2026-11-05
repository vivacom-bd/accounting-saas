<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Actions\Reports\ClaimDueReportSchedules;
use App\Events\Notifications\NotificationEvent;
use App\Models\Organisation\Company;
use App\Models\Reports\ReportSchedule;
use App\Support\Actor;
use App\Support\Outbox\OutboxPublisher;
use App\Support\Tenancy\TenantAware;
use App\Support\Tenancy\WithTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly, per tenant (13 §15): takes the scheduled reports that are due in each active company's own timezone and queues one
 * `RunScheduledReport` per due date taken (plan 3c.23). Taking a date is unique in the database, so overlapping passes queue it once.
 */
final class RunTenantReportSchedules implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(public readonly string $tenant)
    {
        $this->onQueue('maintenance');
    }

    public function tenantId(): string
    {
        return $this->tenant;
    }

    public function companyId(): ?string
    {
        return null;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [app(WithTenantContext::class)];
    }

    public function handle(ClaimDueReportSchedules $claim, OutboxPublisher $outbox): void
    {
        foreach (Company::query()->where('status', 'active')->orderBy('id')->get() as $company) {
            try {
                $result = $claim->handle(Actor::system($this->tenant, $company->id), $company);
                foreach ($result['claimed'] as $runId) {
                    RunScheduledReport::dispatch($this->tenant, $company->id, $runId);
                }
                foreach ($result['stuck'] as $run) {
                    $owner = ReportSchedule::query()->whereKey($run->schedule_id)->value('user_id');
                    if (is_string($owner)) {
                        $outbox->publish(new NotificationEvent(NotificationEvent::REPORT_FAILED, [
                            'company_id' => $company->id, 'user_id' => $owner, 'kind' => 'scheduled', 'report' => 'report', 'message' => (string) $run->message,
                        ]));
                    }
                }
            } catch (Throwable $e) {
                Log::error('Scheduled reports failed for a company.', ['tenant_id' => $this->tenant, 'company_id' => $company->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
