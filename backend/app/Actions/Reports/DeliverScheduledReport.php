<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Contracts\Clock;
use App\Events\Notifications\NotificationEvent;
use App\Mail\DocumentMail;
use App\Models\Organisation\Company;
use App\Models\Platform\User;
use App\Models\Reports\ReportLayout;
use App\Models\Reports\ReportSchedule;
use App\Models\Reports\ReportScheduleRun;
use App\Queries\Reports\LayoutResolver;
use App\Queries\Reports\ScheduleRecipients;
use App\Services\Documents\DocumentLook;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportExport;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Audit\DetachedAudit;
use App\Support\Outbox\OutboxPublisher;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Makes one claimed scheduled report and mails it (plan 3c.23). Runs inside the tenant, as the schedule's owner, never as the
 * system: the owner's rights are checked again now, and if they have lost them the schedule is paused, the run is closed as
 * skipped and the owner is told (`report.failed`). A report is read-only: nothing here writes to the ledger.
 *
 * The figures go as an attachment only. The mail body names the report, company and schedule and carries no numbers. Limits: the
 * attachment is at most `reports.schedule_max_bytes`; a company sends at most `reports.scheduled_emails_per_hour` scheduled mails
 * an hour (over it the run waits: `deferred`). Whatever happens the run row ends `sent`, `failed` or `skipped` with plain words,
 * the owner is told of a failure, and it is not retried for that date: the next due date goes ahead as normal.
 */
final class DeliverScheduledReport
{
    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    public const string DEFERRED = 'deferred';

    public const string NOTHING = 'nothing';

    private const array TYPES = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv'];

    public function __construct(
        private readonly Clock $clock,
        private readonly ReportAccess $access,
        private readonly LayoutResolver $resolver,
        private readonly ReportExport $exports,
        private readonly ScheduleRecipients $people,
        private readonly DocumentLook $look,
        private readonly DetachedAudit $audit,
        private readonly OutboxPublisher $outbox,
    ) {}

    /** @param bool $giveUp true on the last attempt: waiting for the hourly limit is over */
    public function handle(Actor $system, string $runId, bool $giveUp = false): string
    {
        // Take the run: only one worker moves it from queued to running.
        $taken = ReportScheduleRun::query()->whereKey($runId)->whereIn('status', ['queued'])->update(['status' => 'running']);
        if ($taken === 0) {
            return self::NOTHING;
        }
        $run = ReportScheduleRun::query()->findOrFail($runId);
        $schedule = ReportSchedule::query()->find($run->schedule_id);
        $layout = $schedule === null ? null : ReportLayout::query()->find($schedule->layout_id);
        $company = Company::query()->find($run->company_id);
        if ($schedule === null || $layout === null || $company === null) {
            return $this->close($run, null, self::SKIPPED, 'The schedule no longer exists, so nothing was sent.');
        }
        $owner = new Actor(ActorType::User, $schedule->user_id, $system->tenantId, $company->id);

        $refusal = $this->access->refusal($owner, $layout->report_code, $company->id);
        if ($refusal !== null) {
            $schedule->forceFill(['active' => false, 'paused_reason' => 'owner_lost_rights'])->save();

            return $this->close($run, $schedule, self::SKIPPED, $refusal.' The schedule was paused and nothing was sent.', $layout, notify: true);
        }

        $limiter = 'scheduled-reports:'.$company->id;
        if (RateLimiter::tooManyAttempts($limiter, (int) config('reports.scheduled_emails_per_hour'))) {
            if (! $giveUp) {
                $run->forceFill(['status' => 'queued'])->save();

                return self::DEFERRED;
            }

            return $this->close($run, $schedule, self::FAILED, 'This company reached its limit of scheduled emails for the hour, and the report waited too long. Nothing was sent for this date; the next one goes ahead as scheduled.', $layout, notify: true);
        }

        $bytes = 0;
        $addresses = [];
        try {
            $due = new DateTimeImmutable($run->due_on, new DateTimeZone($company->timezone));
            $input = $this->resolver->input($company, $layout->report_code, $layout->params, $due);
            $file = tempnam(sys_get_temp_dir(), 'scheduled-report-');
            if ($file === false) {
                throw new \RuntimeException('No temporary file.');
            }
            try {
                $plan = ReportExport::onExportConnection(fn (): array => $this->exports->plan($layout->report_code, $company, $input));
                ReportExport::onExportConnection(fn () => $this->exports->write($plan['table'], $schedule->format, $file));
                $bytes = (int) filesize($file);
                if ($bytes > (int) config('reports.schedule_max_bytes')) {
                    return $this->close($run, $schedule, self::FAILED, sprintf(
                        'The %s is %.1f MB, over the %d MB an email can carry. Nothing was sent for this date. Narrow the report, or use CSV, which is smaller.',
                        strtoupper($schedule->format), $bytes / 1048576, (int) config('reports.schedule_max_bytes') / 1048576,
                    ), $layout, notify: true);
                }
                $data = (string) file_get_contents($file);
            } finally {
                @unlink($file);
            }

            $addresses = $this->addresses($company->id, $schedule);
            if ($addresses === []) {
                return $this->close($run, $schedule, self::FAILED, 'None of the people chosen can receive it any more, and no other address was added. Nothing was sent. Open the schedule and choose recipients.', $layout, notify: true);
            }
            $title = Str::headline($layout->report_code);
            $look = $this->look->for($company->id);
            $name = Str::slug($layout->report_code).'-'.Str::slug($company->name).'-'.$run->due_on.'.'.$schedule->format;
            Mail::to($addresses)->send(new DocumentMail(
                $company->name, "{$title} for {$company->name}, {$run->due_on}",
                "Your scheduled report \"{$layout->name}\" ({$title}) for {$company->name} is attached as ".strtoupper($schedule->format).".\n\nIt was made for {$run->due_on} on the schedule the sender set up in Ledgerly. The figures are in the attachment only.",
                $name, $data, $look['sender_name'], $look['reply_to'], self::TYPES[$schedule->format],
            ));
            RateLimiter::hit($limiter, 3600);
        } catch (ValidationException $e) {
            return $this->close($run, $schedule, self::FAILED, 'The report could not be made: '.collect($e->errors())->flatten()->first().' Nothing was sent for this date.', $layout, notify: true);
        } catch (Throwable $e) {
            report($e);

            return $this->close($run, $schedule, self::FAILED, 'The report could not be made or sent. Nothing was sent for this date; the next one goes ahead as scheduled.', $layout, notify: true);
        }

        return $this->close($run, $schedule, self::SENT, null, $layout, recipients: count($addresses), bytes: $bytes);
    }

    /** @return list<string> the addresses today: people still in the company, and the outside addresses */
    private function addresses(string $companyId, ReportSchedule $schedule): array
    {
        $members = [];
        foreach (($this->people)($companyId) as $p) {
            $members[$p['id']] = $p['email'];
        }
        $out = [];
        foreach ($schedule->recipient_user_ids as $id) {
            if (isset($members[$id])) {
                $out[strtolower($members[$id])] = $members[$id];
            }
        }
        foreach ($schedule->recipient_emails as $email) {
            $out[strtolower($email)] = $email;
        }

        return array_values($out);
    }

    private function close(ReportScheduleRun $run, ?ReportSchedule $schedule, string $status, ?string $message, ?ReportLayout $layout = null, bool $notify = false, int $recipients = 0, ?int $bytes = null): string
    {
        $now = $this->clock->now()->format('Y-m-d H:i:sP');
        $run->forceFill(['status' => $status, 'message' => $message, 'recipients' => $recipients, 'bytes' => $bytes, 'finished_at' => $now])->save();
        if ($schedule !== null) {
            ReportSchedule::query()->whereKey($schedule->id)->update(['last_run_at' => $now, 'last_status' => $status]);
            $owner = new Actor(ActorType::User, $schedule->user_id, $run->tenant_id, $run->company_id);
            $this->audit->record($owner, 'report_schedule.run_'.$status, $schedule, ['run_id' => $run->id, 'due_on' => $run->due_on, 'report' => $layout?->report_code, 'recipients' => $recipients]);
            if ($notify && User::query()->whereKey($schedule->user_id)->exists()) {
                $this->outbox->publish(new NotificationEvent(NotificationEvent::REPORT_FAILED, [
                    'company_id' => $run->company_id, 'user_id' => $schedule->user_id, 'report' => $layout->report_code ?? 'report', 'kind' => 'scheduled',
                    'message' => (string) $message, 'schedule_id' => $schedule->id, 'layout_id' => $schedule->layout_id,
                ]));
            }
        }

        return $status;
    }
}
