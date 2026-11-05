<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Actions\Reports\DeliverScheduledReport;
use App\Models\Reports\ReportScheduleRun;
use App\Support\Actor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One scheduled report on the `exports` queue (plan 3c.23): makes the file and mails it inside the tenant, as the schedule's owner.
 * A run over the company's hourly limit is put back and tried again in `reports.schedule_retry_minutes`; the last of its attempts
 * gives up and says so. Other failures are handled inside the Action and end the run with a message; `failed()` below is the net for
 * anything that escaped (the run is closed so it does not sit as `running`).
 */
final class RunScheduledReport implements ShouldQueue
{
    use Queueable;

    public const int ATTEMPTS = 7;

    public int $tries = self::ATTEMPTS;

    public function __construct(public readonly string $tenantId, public readonly string $companyId, public readonly string $runId)
    {
        $this->onQueue('exports');
    }

    public function handle(TenantContext $tenant, DeliverScheduledReport $deliver): void
    {
        $outcome = $tenant->run($this->tenantId, fn (): string => $deliver->handle(Actor::system($this->tenantId, $this->companyId), $this->runId, $this->attempts() >= self::ATTEMPTS));
        if ($outcome === DeliverScheduledReport::DEFERRED) {
            $this->release(((int) config('reports.schedule_retry_minutes')) * 60);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        app(TenantContext::class)->run($this->tenantId, function (): void {
            ReportScheduleRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
                'status' => 'failed', 'finished_at' => now(), 'message' => 'The report could not be made or sent. Nothing was sent for this date; the next one goes ahead as scheduled.',
            ]);
        });
    }
}
