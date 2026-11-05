<?php

declare(strict_types=1);

use App\Domain\Reports\ScheduleRule;
use App\Jobs\Reports\RunReportExport;
use App\Services\Reports\ExportStore;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixture;
use Tests\Support\ReportScenario;

/*
 * Saved report layouts (plan 3c.23): named parameters, private or shared, a default per report, a period rule that moves with
 * the calendar, and the rights around them. The test clock is 2026-03-15 09:30 UTC; the company's year has monthly periods.
 */

beforeEach(function (): void {
    $this->setUpWorld();
    $this->owner = $this->person('Olga Owner', ['Owner']);
    $this->viewer = $this->person('Vic Viewer', ['Viewer']);
    $this->accountant = $this->person('Ann Accountant', ['Accountant']);
    $this->sales = $this->person('Sam Sales', ['Sales Assistant']);
    $this->s = new ReportScenario($this->f);
    $this->s->actor = new Actor(ActorType::User, $this->owner->id, $this->f->tenantId, $this->f->companyId, hasSigningGrant: true);
    $this->s->chart();
    $this->inTenant(fn () => $this->s->story());
    $this->feb = $this->inTenant(fn () => $this->s->periodId('2026-02-15'));
    $this->mar = $this->inTenant(fn () => $this->s->periodId('2026-03-15'));
    $this->save = fn ($who, array $over = [], int $status = 201) => $this->asPerson($who)->postJson($this->api('reports/layouts'), $over + [
        'report' => 'profit-and-loss', 'name' => 'Last month', 'visibility' => 'private', 'params' => ['rule' => 'last_month'],
    ])->assertStatus($status);
});

it('keeps a layout private to its owner and shows a shared one to the company', function (): void {
    $private = ($this->save)($this->accountant)->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.mine', true)->json('data.id');
    ($this->save)($this->accountant, ['name' => 'For everyone', 'visibility' => 'company']);

    $mine = $this->asPerson($this->accountant)->getJson($this->api('reports/layouts?report=profit-and-loss'))->assertOk()->json('data');
    $theirs = $this->asPerson($this->owner)->getJson($this->api('reports/layouts'))->assertOk()->json('data');
    expect(array_column($mine, 'name'))->toBe(['For everyone', 'Last month'])
        ->and(array_column($theirs, 'name'))->toBe(['For everyone'])
        ->and($theirs[0]['mine'])->toBeFalse()->and($theirs[0]['owner_name'])->toBe('Ann Accountant');

    // Only the owner changes or removes it, and a private layout of someone else does not exist for you.
    $this->asPerson($this->owner)->putJson($this->api("reports/layouts/{$private}"), ['report' => 'profit-and-loss', 'name' => 'Mine now', 'visibility' => 'private', 'params' => ['rule' => 'last_month']])->assertStatus(422);
    $this->asPerson($this->owner)->deleteJson($this->api("reports/layouts/{$private}"))->assertStatus(422);
    $shared = collect($mine)->firstWhere('name', 'For everyone')['id'];
    $this->asPerson($this->owner)->deleteJson($this->api("reports/layouts/{$shared}"))->assertStatus(403);
    $this->asPerson($this->accountant)->deleteJson($this->api("reports/layouts/{$private}"))->assertNoContent();
});

it('works out a period rule against the calendar today and never saves figures', function (): void {
    $id = ($this->save)($this->accountant)->assertJsonPath('data.resolved.period_id', $this->feb)->assertJsonPath('data.resolved.basis', 'month')->json('data.id');
    ($this->save)($this->accountant, ['name' => 'YTD', 'params' => ['rule' => 'this_year_to_date', 'comparison' => 'previous_year', 'hide_zero' => true]])
        ->assertJsonPath('data.resolved.period_id', $this->mar)->assertJsonPath('data.resolved.basis', 'ytd')->assertJsonPath('data.resolved.comparison', 'previous_year')
        ->assertJsonPath('data.resolved.hide_zero', true);
    ($this->save)($this->accountant, ['report' => 'balance-sheet', 'name' => 'Today', 'params' => ['rule' => 'today']])->assertJsonPath('data.resolved.as_of', '2026-03-15');
    ($this->save)($this->accountant, ['report' => 'general-ledger', 'name' => 'Cash', 'params' => ['rule' => 'last_month', 'account_id' => $this->inTenant(fn () => DB::table('accounts')->where('code', '1000')->value('id'))]])
        ->assertJsonPath('data.resolved.from', '2026-02-01')->assertJsonPath('data.resolved.to', '2026-02-28');

    // The date moves, the layout does not: a month later the same layout points at March.
    $this->clock->advance('+31 days');
    $this->asPerson($this->accountant)->getJson($this->api('reports/layouts?report=profit-and-loss'))->assertOk()->assertJsonPath('data.0.resolved.period_id', $this->mar);
    expect($id)->toBeString();
    $stored = $this->inTenant(fn () => DB::table('report_layouts')->first());
    expect($stored->params)->not->toContain('amount');
});

it('refuses a layout that cannot work, with plain words', function (): void {
    ($this->save)($this->accountant, ['params' => ['rule' => 'today']], 422);                                    // today fits an as-of report only
    ($this->save)($this->accountant, ['params' => ['rule' => 'last_month', 'account_id' => (string) Str::uuid()]], 422);
    ($this->save)($this->accountant, ['report' => 'balance-sheet', 'params' => ['rule' => 'today', 'basis' => 'ytd']], 422);
    ($this->save)($this->accountant, ['report' => 'audit-trail', 'params' => []], 422);
    ($this->save)($this->accountant, ['name' => '  '], 422);
    $this->clock->advance('+1000 days');
    ($this->save)($this->accountant, [], 422)->assertJsonPath('code', 'validation_failed');
});

it('names are unique per person and report, and one layout is the default', function (): void {
    $a = ($this->save)($this->accountant, ['is_default' => true])->json('data.id');
    ($this->save)($this->accountant, [], 422)->assertJsonPath('code', 'layout_name_taken');
    $b = ($this->save)($this->accountant, ['name' => 'Other', 'is_default' => true])->json('data.id');
    $list = $this->asPerson($this->accountant)->getJson($this->api('reports/layouts'))->json('data');
    expect(array_column($list, 'id'))->toBe([$b, $a])->and(array_column($list, 'is_default'))->toBe([true, false]);
    // Someone else may use the same name.
    ($this->save)($this->owner);
});

it('lets anyone who views reports keep private layouts, but sharing needs the right to export', function (): void {
    ($this->save)($this->viewer)->assertJsonPath('data.mine', true);
    ($this->save)($this->viewer, ['name' => 'Shared', 'visibility' => 'company'], 403);
    $this->asPerson($this->sales)->getJson($this->api('reports/layouts'))->assertForbidden();
    $this->asPerson($this->sales)->postJson($this->api('reports/layouts'), ['report' => 'profit-and-loss', 'name' => 'x', 'visibility' => 'private', 'params' => []])->assertForbidden();
    // An outdated version is a conflict, not an overwrite.
    $id = ($this->save)($this->accountant)->json('data.id');
    $this->asPerson($this->accountant)->putJson($this->api("reports/layouts/{$id}"), ['report' => 'profit-and-loss', 'name' => 'Renamed', 'visibility' => 'private', 'params' => ['rule' => 'last_month'], 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
    $this->asPerson($this->accountant)->putJson($this->api("reports/layouts/{$id}"), ['report' => 'profit-and-loss', 'name' => 'Again', 'visibility' => 'private', 'params' => ['rule' => 'last_month'], 'version' => 0])->assertStatus(409);
});

it('keeps other companies and other tenants out of the layouts', function (): void {
    $id = ($this->save)($this->accountant, ['visibility' => 'company'])->json('data.id');
    $second = $this->secondCompany();
    // Nobody reaches the layout through another company's address: not even with the right id.
    $this->asPerson($this->owner)->getJson($this->api('reports/layouts', $second->id))->assertForbidden();
    $this->asPerson($this->owner)->deleteJson($this->api("reports/layouts/{$id}", $second->id))->assertForbidden();
    expect($this->inTenant(fn () => DB::table('report_layouts')->where('company_id', $second->id)->count()))->toBe(0);

    $beta = LedgerFixture::create('USD', 'beta');
    app(TenantContext::class)->run($beta->tenantId, function () use ($id): void {
        expect(DB::table('report_layouts')->count())->toBe(0)->and(DB::table('report_layouts')->where('id', $id)->exists())->toBeFalse();
    });
    expect($this->inTenant(fn () => DB::table('report_layouts')->count()))->toBe(1);
});

it('knows when a schedule is due, in friendly terms', function (): void {
    $at = fn (string $when) => new DateTimeImmutable($when);
    expect(ScheduleRule::dueOn('daily', null, null, false, 7, $at('2026-03-15 06:59')))->toBeNull()
        ->and(ScheduleRule::dueOn('daily', null, null, false, 7, $at('2026-03-15 07:00')))->toBe('2026-03-15')
        ->and(ScheduleRule::dueOn('weekly', 1, null, false, 7, $at('2026-03-16 08:00')))->toBe('2026-03-16')      // a Monday
        ->and(ScheduleRule::dueOn('weekly', 1, null, false, 7, $at('2026-03-17 08:00')))->toBeNull()
        ->and(ScheduleRule::dueOn('monthly', null, 5, false, 7, $at('2026-04-05 08:00')))->toBe('2026-04-05')
        ->and(ScheduleRule::dueOn('monthly', null, null, true, 7, $at('2026-02-28 08:00')))->toBe('2026-02-28')    // last day of a short month
        ->and(ScheduleRule::dueOn('monthly', null, null, true, 7, $at('2026-02-27 08:00')))->toBeNull()
        ->and(ScheduleRule::dueOn('quarterly', null, 1, false, 7, $at('2026-04-01 08:00')))->toBe('2026-04-01')
        ->and(ScheduleRule::dueOn('quarterly', null, 1, false, 7, $at('2026-05-01 08:00')))->toBeNull()
        ->and(ScheduleRule::next('weekly', 1, null, false, 7, $at('2026-03-16 08:00'))?->format('Y-m-d H:i'))->toBe('2026-03-23 07:00')
        ->and(ScheduleRule::describe('weekly', 1, null, false, 7))->toBe('Every Monday at 07:00');
});

it('tells the person when a queued export fails, and the screen can show why', function (): void {
    $id = (string) Str::uuid7();
    $this->inTenant(fn () => app(ExportStore::class)->queued($this->f->tenantId, $this->f->companyId, $id, $this->accountant->id, 'trial-balance', 'pdf'));
    $job = new RunReportExport($this->f->tenantId, $this->f->companyId, $id, 'trial-balance', 'pdf', ['as_of' => '2026-02-28']);
    $job->failed(new RuntimeException('Chromium exploded at /usr/bin/secret-path'));

    $this->asPerson($this->accountant)->getJson($this->api("reports/exports/{$id}"))->assertOk()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.message', fn ($m) => str_contains($m, 'PDF could not be made') && ! str_contains($m, 'Chromium'));
    $note = $this->inTenant(fn () => DB::table('user_notifications')->where('kind', 'report_failed')->first());
    expect($note)->not->toBeNull()->and($note->user_id)->toBe($this->accountant->id)->and($note->body)->toContain('trial balance export did not finish')->and($note->body)->not->toContain('secret-path');
    // Someone else cannot read another person's export.
    $this->asPerson($this->owner)->getJson($this->api("reports/exports/{$id}"))->assertNotFound();
});

it('has a unique key per schedule and due date in the database', function (): void {
    $layout = ($this->save)($this->accountant)->json('data.id');
    $this->asPerson($this->accountant)->putJson($this->api("reports/layouts/{$layout}/schedule"), [
        'frequency' => 'daily', 'hour' => 7, 'format' => 'csv', 'recipient_user_ids' => [], 'recipient_emails' => ['cfo@example.com'],
    ])->assertOk();
    $schedule = $this->inTenant(fn () => DB::table('report_schedules')->value('id'));
    $row = fn () => ['id' => (string) Str::uuid7(), 'tenant_id' => $this->f->tenantId, 'company_id' => $this->f->companyId, 'schedule_id' => $schedule, 'due_on' => '2026-03-15', 'status' => 'queued', 'queued_at' => now()];
    $this->inTenant(fn () => DB::table('report_schedule_runs')->insert($row()));
    expect(fn () => $this->inTenant(fn () => DB::table('report_schedule_runs')->insert($row())))->toThrow(QueryException::class);
});
