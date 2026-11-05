<?php

declare(strict_types=1);

use App\Actions\Reports\ClaimDueReportSchedules;
use App\Actions\Reports\DeliverScheduledReport;
use App\Contracts\HtmlToPdf;
use App\Models\Organisation\Company;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;
use Tests\Support\FakePdfEngine;
use Tests\Support\LedgerFixture;
use Tests\Support\ReportScenario;

/*
 * Scheduled report delivery (plan 3c.23): friendly schedules, recipients limited to the company or typed addresses, one run per
 * schedule and due date, runs as the owner and stops (and says so) when the owner has lost the rights, the file goes as an
 * attachment with no figures in the body, size and rate limits, and a run history. The test clock is 2026-03-15 09:30 UTC.
 */

beforeEach(function (): void {
    $this->setUpWorld();
    $this->owner = $this->person('Olga Owner', ['Owner']);
    $this->accountant = $this->person('Ann Accountant', ['Accountant']);
    $this->approver = $this->person('Abe Approver', ['Approver']);
    $this->viewer = $this->person('Vic Viewer', ['Viewer']);
    $this->s = new ReportScenario($this->f);
    $this->s->actor = new Actor(ActorType::User, $this->owner->id, $this->f->tenantId, $this->f->companyId, hasSigningGrant: true);
    $this->s->chart();
    $this->inTenant(fn () => $this->s->story());
    $this->engine = new FakePdfEngine;
    app()->instance(HtmlToPdf::class, $this->engine);

    $this->layout = function (string $name = 'Monthly P&L', array $over = []): string {
        return $this->asPerson($this->accountant)->postJson($this->api('reports/layouts'), $over + [
            'report' => 'profit-and-loss', 'name' => $name, 'visibility' => 'private', 'params' => ['rule' => 'last_month'],
        ])->assertCreated()->json('data.id');
    };
    $this->schedule = fn (string $layout, array $over = [], int $status = 200) => $this->asPerson($this->accountant)->putJson($this->api("reports/layouts/{$layout}/schedule"), $over + [
        'frequency' => 'daily', 'hour' => 7, 'format' => 'csv', 'recipient_user_ids' => [$this->approver->id], 'recipient_emails' => ['cfo@client.example'],
    ])->assertStatus($status);
    /** @return list<Email> */
    $this->mails = fn (): array => array_map(fn ($m) => $m->getOriginalMessage(), app('mailer')->getSymfonyTransport()->messages()->all());
    $this->tick = function (): void {
        app(TenantContext::class)->clear();
        $this->artisan('reports:run-schedules')->assertExitCode(0);
    };
    $this->runs = fn (): array => $this->inTenant(fn () => DB::table('report_schedule_runs')->orderBy('due_on')->get()->all());
});

it('sends the report as an attachment on schedule, with no figures in the mail, and logs the run', function (): void {
    $layout = ($this->layout)();
    ($this->schedule)($layout)->assertJsonPath('data.description', 'Every day at 07:00')->assertJsonPath('data.next_run_at', '2026-03-16T07:00');

    ($this->tick)();

    $mails = ($this->mails)();
    expect($mails)->toHaveCount(1);
    $mail = $mails[0];
    $name = $this->inTenant(fn () => Company::query()->first()?->name ?? '');
    expect($mail->getSubject())->toBe("Profit And Loss for {$name}, 2026-03-15")
        ->and(array_map(fn ($a) => $a->getAddress(), $mail->getTo()))->toEqualCanonicalizing(['cfo@client.example', 'abe.approver3@test.example'])
        ->and($mail->getAttachments())->toHaveCount(1)
        ->and($mail->getAttachments()[0]->getFilename())->toBe('profit-and-loss-'.Str::slug($name).'-2026-03-15.csv')
        ->and($mail->getTextBody())->toContain('attached as a CSV file')->toContain('The figures are in the attachment only')->not->toMatch('/\d{1,3},\d{3}\.\d{2}/');
    $csv = $mail->getAttachments()[0]->getBody();
    expect($csv)->toContain('Revenue');

    $run = ($this->runs)()[0];
    expect($run->status)->toBe('sent')->and($run->recipients)->toBe(2)->and($run->bytes)->toBeGreaterThan(0)->and($run->due_on)->toBe('2026-03-15');
    $history = $this->asPerson($this->accountant)->getJson($this->api("reports/layouts/{$layout}/schedule"))->assertOk();
    $history->assertJsonPath('data.last_status', 'sent')->assertJsonPath('data.runs.0.status', 'sent')->assertJsonPath('data.recipient_people.0.name', 'Abe Approver');
    // It is read-only: the ledger has not changed and the run is in the audit log.
    $this->inTenant(fn () => expect(DB::table('audit_events')->where('action', 'report_schedule.run_sent')->count())->toBe(1));
});

it('takes each due date once however often the scheduler runs, and again on the next due date', function (): void {
    ($this->schedule)(($this->layout)());
    ($this->tick)();
    ($this->tick)();
    $this->artisan('reports:run-schedules');
    expect(($this->mails)())->toHaveCount(1)->and(($this->runs)())->toHaveCount(1);

    // The claim itself is idempotent, not just the command: a second claim for the same date gets nothing.
    $this->inTenant(function (): void {
        $claim = app(ClaimDueReportSchedules::class);
        $company = Company::query()->firstOrFail();
        expect($claim->handle(Actor::system($this->f->tenantId, $company->id), $company)['claimed'])->toBe([]);
    });

    $this->clock->advance('+1 day');
    ($this->tick)();
    expect(($this->mails)())->toHaveCount(2)->and(array_column(($this->runs)(), 'due_on'))->toBe(['2026-03-15', '2026-03-16']);
});

it('waits for the hour, in the company\'s own timezone', function (): void {
    ($this->schedule)(($this->layout)(), ['hour' => 12]);
    ($this->tick)();
    expect(($this->mails)())->toBe([]);
    $this->clock->advance('+3 hours');                       // 12:30 UTC
    ($this->tick)();
    expect(($this->mails)())->toHaveCount(1);

    // A company on another clock: 09:30 UTC is 18:30 in Tokyo, so a 07:00 schedule there is long past and its date is the same day.
    $this->inTenant(fn () => Company::query()->update(['timezone' => 'Asia/Tokyo']));
    $this->clock->advance('+16 hours');                      // 2026-03-16 04:30 UTC = 13:30 in Tokyo
    ($this->tick)();
    expect(array_column(($this->runs)(), 'due_on'))->toBe(['2026-03-15', '2026-03-16']);
});

it('runs weekly, monthly and on the last day only when due', function (): void {
    ($this->schedule)(($this->layout)('Weekly'), ['frequency' => 'weekly', 'weekday' => 2]);      // Tuesdays; 2026-03-15 is a Sunday
    ($this->schedule)(($this->layout)('Month end'), ['frequency' => 'monthly', 'last_day' => true]);
    ($this->tick)();
    expect(($this->mails)())->toBe([]);
    $this->clock->advance('+2 days');                        // Tuesday 17th
    ($this->tick)();
    expect(($this->mails)())->toHaveCount(1);
    $this->clock->advance('+14 days');                       // Tuesday 31st: both
    ($this->tick)();
    expect(($this->mails)())->toHaveCount(3);
});

it('needs reports.schedule, a moving period, and recipients from the company or valid addresses', function (): void {
    $fixed = ($this->layout)('Fixed', ['params' => ['period_id' => $this->inTenant(fn () => DB::table('fiscal_periods')->value('id'))]]);
    ($this->schedule)($fixed, [], 422)->assertJsonPath('code', 'schedule_needs_rule');
    $layout = ($this->layout)();
    ($this->schedule)($layout, ['recipient_user_ids' => [], 'recipient_emails' => []], 422)->assertJsonPath('code', 'recipient_required');
    ($this->schedule)($layout, ['recipient_emails' => ['not an email']], 422)->assertJsonPath('code', 'recipient_invalid');
    ($this->schedule)($layout, ['recipient_emails' => array_map(fn ($i) => "p{$i}@x.example", range(1, 11))], 422);
    ($this->schedule)($layout, ['frequency' => 'monthly', 'month_day' => 31], 422);
    ($this->schedule)($layout, ['frequency' => 'weekly'], 422)->assertJsonPath('code', 'schedule_invalid');
    // Someone who is not in the company cannot be chosen by id.
    $outsider = (string) Str::uuid7();
    DB::table('users')->insert(['id' => $outsider, 'name' => 'Out Sider', 'email' => 'out@test.example', 'password' => 'x']);
    ($this->schedule)($layout, ['recipient_user_ids' => [$outsider]], 422)->assertJsonPath('code', 'recipient_invalid');

    // Viewing and exporting is not enough: the Viewer and the Approver cannot schedule, and nobody schedules another person's layout.
    $this->asPerson($this->viewer)->putJson($this->api("reports/layouts/{$layout}/schedule"), [])->assertForbidden();
    $this->asPerson($this->approver)->putJson($this->api("reports/layouts/{$layout}/schedule"), [])->assertForbidden();
    $this->asPerson($this->owner)->putJson($this->api("reports/layouts/{$layout}/schedule"), [
        'frequency' => 'daily', 'hour' => 7, 'format' => 'csv', 'recipient_user_ids' => [], 'recipient_emails' => ['a@x.example'],
    ])->assertStatus(422)->assertJsonPath('code', 'layout_not_found');
    $this->asPerson($this->accountant)->getJson($this->api('reports/schedule-recipients'))->assertOk()->assertJsonFragment(['name' => 'Abe Approver']);
    $this->asPerson($this->viewer)->getJson($this->api('reports/schedule-recipients'))->assertForbidden();
});

it('stops, pauses the schedule and tells the owner when the owner has lost the rights', function (): void {
    $layout = ($this->layout)();
    ($this->schedule)($layout);
    // The accountant's role is taken away before the run.
    $this->inTenant(fn () => DB::table('model_has_roles')->where('model_id', $this->accountant->id)->delete());
    $this->inTenant(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());

    ($this->tick)();

    expect(($this->mails)())->toBe([]);
    $run = ($this->runs)()[0];
    expect($run->status)->toBe('skipped')->and($run->message)->toContain('no longer have access')->and($run->message)->toContain('paused');
    $schedule = $this->inTenant(fn () => DB::table('report_schedules')->first());
    expect((bool) $schedule->active)->toBeFalse()->and($schedule->paused_reason)->toBe('owner_lost_rights');
    $note = $this->inTenant(fn () => DB::table('user_notifications')->where('user_id', $this->accountant->id)->where('kind', 'report_failed')->first());
    expect($note)->not->toBeNull()->and($note->title)->toBe('A scheduled report did not go out')->and($note->body)->toContain('paused');

    // A paused schedule is left alone from then on.
    $this->clock->advance('+1 day');
    ($this->tick)();
    expect(($this->runs)())->toHaveCount(1);
});

it('stops when only the right to schedule is gone, and runs as the owner not the system', function (): void {
    $layout = ($this->layout)();
    ($this->schedule)($layout);
    $perm = $this->inTenant(fn () => DB::table('permissions')->where('name', 'reports.schedule')->pluck('id')->all());
    $deleted = $this->inTenant(fn () => DB::table('role_has_permissions')->whereIn('permission_id', $perm)->delete());
    expect($deleted)->toBeGreaterThan(0);
    $this->inTenant(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    ($this->tick)();
    $run = ($this->runs)()[0];
    expect($run->status)->toBe('skipped')->and($run->message)->toContain('can no longer schedule reports');
});

it('says so when the report cannot be made or sent, and does not retry that date', function (): void {
    $layout = ($this->layout)('PDF', ['params' => ['rule' => 'last_month']]);
    ($this->schedule)($layout, ['format' => 'pdf']);
    $this->engine->fail = new RuntimeException('Chromium crashed in /opt/secret');
    ($this->tick)();
    ($this->tick)();
    $run = ($this->runs)()[0];
    expect($run->status)->toBe('failed')->and($run->message)->toContain('Nothing was sent for this date')->and($run->message)->not->toContain('secret')->and(($this->mails)())->toBe([]);
    $note = $this->inTenant(fn () => DB::table('user_notifications')->where('kind', 'report_failed')->first());
    expect($note->body)->toContain('scheduled profit and loss')->and($note->user_id)->toBe($this->accountant->id);
    expect($this->asPerson($this->accountant)->getJson($this->api("reports/layouts/{$layout}/schedule"))->json('data.runs.0.message'))->toBe($run->message);
});

it('refuses an attachment over the size limit and says what to do', function (): void {
    config(['reports.schedule_max_bytes' => 10]);
    ($this->schedule)(($this->layout)());
    ($this->tick)();
    $run = ($this->runs)()[0];
    expect($run->status)->toBe('failed')->and($run->message)->toContain('over the 0 MB')->and($run->message)->toContain('use CSV')->and(($this->mails)())->toBe([]);
});

it('holds back scheduled mail over the hourly limit and gives up with a message', function (): void {
    config(['reports.scheduled_emails_per_hour' => 1]);
    ($this->schedule)(($this->layout)('One'));
    ($this->schedule)(($this->layout)('Two'));
    RateLimiter::clear('scheduled-reports:'.$this->f->companyId);
    ($this->tick)();
    // The first went out; the second waited (its job released itself, which the sync queue treats as done), so its run is still queued.
    expect(($this->mails)())->toHaveCount(1);
    $statuses = array_column(($this->runs)(), 'status');
    sort($statuses);
    expect($statuses)->toBe(['queued', 'sent']);

    $queued = collect(($this->runs)())->firstWhere('status', 'queued');
    $this->inTenant(function () use ($queued): void {
        $outcome = app(DeliverScheduledReport::class)->handle(Actor::system($this->f->tenantId, $this->f->companyId), $queued->id, giveUp: true);
        expect($outcome)->toBe('failed');
    });
    expect(collect(($this->runs)())->firstWhere('id', $queued->id)->message)->toContain('limit of scheduled emails');
});

it('closes a run that never finished and tells its owner', function (): void {
    $layout = ($this->layout)();
    ($this->schedule)($layout);
    $schedule = $this->inTenant(fn () => DB::table('report_schedules')->value('id'));
    $this->inTenant(fn () => DB::table('report_schedule_runs')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->f->tenantId, 'company_id' => $this->f->companyId, 'schedule_id' => $schedule,
        'due_on' => '2026-03-14', 'status' => 'running', 'queued_at' => '2026-03-14 06:00:00+00',
    ]));
    ($this->tick)();
    $stuck = collect(($this->runs)())->firstWhere('due_on', '2026-03-14');
    expect($stuck->status)->toBe('failed')->and($stuck->message)->toContain('never finished');
    expect($this->inTenant(fn () => DB::table('user_notifications')->where('kind', 'report_failed')->count()))->toBe(1);
});

it('keeps tenants and companies apart: a tick for one tenant never touches another\'s schedules', function (): void {
    ($this->schedule)(($this->layout)());
    $beta = LedgerFixture::create('USD', 'beta');
    ($this->tick)();
    app(TenantContext::class)->run($beta->tenantId, function (): void {
        expect(DB::table('report_schedules')->count())->toBe(0)->and(DB::table('report_schedule_runs')->count())->toBe(0)->and(DB::table('report_layouts')->count())->toBe(0);
    });
    expect(($this->runs)())->toHaveCount(1)->and(($this->mails)())->toHaveCount(1);
    // Deleting the layout takes its schedule and history with it.
    $layout = $this->inTenant(fn () => DB::table('report_layouts')->value('id'));
    $this->asPerson($this->accountant)->deleteJson($this->api("reports/layouts/{$layout}"))->assertNoContent();
    expect($this->inTenant(fn () => DB::table('report_schedules')->count() + DB::table('report_schedule_runs')->count()))->toBe(0);
});
