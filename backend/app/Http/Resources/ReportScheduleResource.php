<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Contracts\Clock;
use App\Domain\Reports\ScheduleRule;
use App\Models\Organisation\Company;
use App\Models\Reports\ReportSchedule;
use App\Models\Reports\ReportScheduleRun;
use App\Queries\Reports\ScheduleRecipients;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A layout's scheduled delivery with its recent run history (plan 3c.23). Only the layout's owner is shown it.
 *
 * @mixin ReportSchedule
 */
final class ReportScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $company = Company::query()->findOrFail($this->company_id);
        $local = app(Clock::class)->now()->setTimezone(new DateTimeZone($company->timezone));
        $next = $this->active ? ScheduleRule::next($this->frequency, $this->weekday, $this->month_day, $this->last_day, $this->hour, $local) : null;
        $people = collect(app(ScheduleRecipients::class)($this->company_id))->keyBy('id');
        $runs = ReportScheduleRun::query()->where('schedule_id', $this->id)->orderByDesc('due_on')->limit(10)->get();

        return [
            'id' => $this->id,
            'layout_id' => $this->layout_id,
            'frequency' => $this->frequency,
            'weekday' => $this->weekday,
            'month_day' => $this->month_day,
            'last_day' => $this->last_day,
            'hour' => $this->hour,
            'format' => $this->format,
            /** In words: "Every Monday at 07:00". */
            'description' => ScheduleRule::describe($this->frequency, $this->weekday, $this->month_day, $this->last_day, $this->hour),
            'timezone' => $company->timezone,
            'recipient_user_ids' => $this->recipient_user_ids,
            /** Names and addresses of the chosen people, for people who still have access; others are listed in `recipient_user_ids` only. */
            /** @var list<array{id: string, name: string, email: string}> */
            'recipient_people' => collect($this->recipient_user_ids)->map(fn (string $id) => $people->get($id))->filter()->values()->all(),
            'recipient_emails' => $this->recipient_emails,
            'active' => $this->active,
            /** paused_by_owner | owner_lost_rights when `active` is false. */
            'paused_reason' => $this->paused_reason,
            /** The next run, as the company's own local time without offset (YYYY-MM-DDTHH:MM); null when paused. */
            /** @var string|null */
            'next_run_at' => $next?->format('Y-m-d\TH:i'),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'last_status' => $this->last_status,
            /** @var list<array{id: string, due_on: string, status: string, message: string|null, recipients: int, bytes: int|null, finished_at: string|null}> */
            'runs' => $runs->map(fn (ReportScheduleRun $r): array => [
                'id' => $r->id, 'due_on' => (string) $r->due_on, 'status' => $r->status, 'message' => $r->message, 'recipients' => $r->recipients,
                'bytes' => $r->bytes, 'finished_at' => $r->finished_at?->toIso8601String(),
            ])->values()->all(),
            'version' => $this->lock_version,
        ];
    }
}
