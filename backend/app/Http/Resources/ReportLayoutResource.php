<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Contracts\Clock;
use App\Domain\Reports\ScheduleRule;
use App\Models\Organisation\Company;
use App\Models\Platform\User;
use App\Models\Reports\ReportLayout;
use App\Models\Reports\ReportSchedule;
use App\Queries\Reports\LayoutResolver;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

/**
 * A saved report layout (plan 3c.23). `params` is what was saved (with the period rule); `resolved` is the same thing worked out
 * for today in the company's calendar, in the report's own query terms, so the screen can apply it as it is.
 *
 * @mixin ReportLayout
 */
final class ReportLayoutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $company = Company::query()->findOrFail($this->company_id);
        $mine = $this->user_id === (string) $request->user()?->getAuthIdentifier();
        $resolved = null;
        $problem = null;
        try {
            $today = app(Clock::class)->now()->setTimezone(new DateTimeZone($company->timezone));
            $resolved = app(LayoutResolver::class)->input($company, $this->report_code, $this->params, $today);
        } catch (ValidationException $e) {
            $problem = (string) collect($e->errors())->flatten()->first();
        }
        $schedule = $mine ? ReportSchedule::query()->where('layout_id', $this->id)->first() : null;

        return [
            'id' => $this->id,
            'report' => $this->report_code,
            'name' => $this->name,
            /** private | company */
            'visibility' => $this->visibility,
            'mine' => $mine,
            'owner_name' => $mine ? null : (string) User::query()->whereKey($this->user_id)->value('name'),
            'is_default' => $this->is_default,
            'params' => $this->params,
            /** @var array<string, mixed>|null */
            'resolved' => $resolved,
            /** Why the layout cannot be applied today (no period for today in the calendar, say). */
            /** @var string|null */
            'problem' => $problem,
            /** The owner's schedule in short, or null. */
            'schedule' => $schedule === null ? null : [
                'active' => $schedule->active,
                'description' => ScheduleRule::describe($schedule->frequency, $schedule->weekday, $schedule->month_day, $schedule->last_day, $schedule->hour),
                'last_status' => $schedule->last_status,
            ],
            'version' => $this->lock_version,
        ];
    }
}
