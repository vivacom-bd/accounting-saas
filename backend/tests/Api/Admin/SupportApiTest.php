<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use App\Models\Platform\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixture;

/** Admin support tools (plan Task 15, 10 §3.8): search, failed jobs, mail log, webhook log, announcements. */
beforeEach(function (): void {
    $this->withHeaders(['Accept' => 'application/json', 'Referer' => 'http://localhost', 'Origin' => 'http://localhost']);
});

function supDb(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return list<object> */
function supAudit(string $action): array
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', $action)->get()->all();
}

/** @return array{id: string, owner: string} */
function supTenant(string $name, string $slug, string $lifecycle = 'active', string $country = 'KE', ?string $planVersion = null, ?string $email = null): array
{
    $id = (string) Str::uuid7();
    supDb()->table('tenants')->insert([
        'id' => $id, 'name' => $name, 'slug' => $slug, 'lifecycle' => $lifecycle, 'country' => $country,
        'settings' => json_encode($planVersion === null ? [] : ['plan_version_id' => $planVersion]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = (string) Str::uuid7();
    supDb()->table('users')->insert(['id' => $user, 'name' => "Owner of {$name}", 'email' => $email ?? "owner@{$slug}.test", 'password' => 'x']);
    supDb()->table('tenant_users')->insert(['tenant_id' => $id, 'user_id' => $user, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

    return ['id' => $id, 'owner' => $user];
}

function supPlan(string $code): string
{
    $planId = (string) Str::uuid7();
    $versionId = (string) Str::uuid7();
    supDb()->table('plans')->insert(['id' => $planId, 'code' => $code, 'name' => ucfirst($code), 'created_at' => now(), 'updated_at' => now()]);
    supDb()->table('plan_versions')->insert([
        'id' => $versionId, 'plan_id' => $planId, 'version' => 1, 'prices' => '{}', 'limits' => '{}', 'entitlements' => '{}', 'effective_from' => now()->subDay(),
    ]);

    return $versionId;
}

function supFailedJob(?string $uuid = null, string $class = 'App\\Jobs\\SendThing'): string
{
    $uuid ??= (string) Str::uuid();
    supDb()->table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 3, 'data' => ['commandName' => $class, 'command' => 'SECRET-PAYLOAD-BODY']]),
        'exception' => "RuntimeException: boom happened in /app/Jobs/SendThing.php:12\nStack trace:\n#0 secret-frame",
        'failed_at' => now(),
    ]);

    return $uuid;
}

final class SupportProbeMail extends Mailable
{
    public function build(): self
    {
        return $this->subject('Your invoice is ready')->html('<p>BODY-MUST-NOT-BE-STORED</p>');
    }
}

// ---------------------------------------------------------------- search

it('search_finds_by_email_company_and_invoice_number', function (): void {
    $acme = supTenant('Acme Traders', 'acme-traders', email: 'jane.doe@acme.test');
    supTenant('Other Ltd', 'other', email: 'sam@other.test');
    $f = LedgerFixture::create(slug: 'zeta');
    DB::table('companies')->where('id', $f->companyId)->update(['name' => 'Zebra Holdings']);
    app(TenantContext::class)->clear();
    $this->asAdmin(AdminRole::Support);

    $byEmail = $this->getJson('/api/admin/search?q=jane.doe')->assertOk()->json();
    expect(array_column($byEmail['users'], 'email'))->toBe(['jane.doe@acme.test'])
        ->and($byEmail['users'][0]['tenant_ids'])->toBe([$acme['id']])
        ->and($byEmail['tenants'])->toBe([])
        ->and($byEmail)->toHaveKeys(['tenants', 'users', 'companies', 'invoices'])
        ->and($byEmail['invoices'])->toBe([]);

    $byTenant = $this->getJson('/api/admin/search?q=acme-tra')->assertOk()->json();
    expect(array_column($byTenant['tenants'], 'id'))->toBe([$acme['id']]);

    $byCompany = $this->getJson('/api/admin/search?q=zebra')->assertOk()->json();
    expect($byCompany['companies'])->toHaveCount(1)
        ->and($byCompany['companies'][0])->toMatchArray(['id' => $f->companyId, 'name' => 'Zebra Holdings', 'tenant_id' => $f->tenantId]);

    // an email must match from the start (exact or prefix), not anywhere inside
    expect($this->getJson('/api/admin/search?q=acme.test')->assertOk()->json('users'))->toBe([]);
});

it('search escapes LIKE wildcards, needs two characters and returns at most ten of each', function (): void {
    for ($i = 1; $i <= 12; $i++) {
        supTenant("Bulk Tenant {$i}", "bulk-{$i}", email: "bulk{$i}@bulk.test");
    }
    $this->asAdmin(AdminRole::Readonly);

    expect($this->getJson('/api/admin/search?q=%25%25')->assertOk()->json('tenants'))->toBe([])
        ->and($this->getJson('/api/admin/search?q=_u')->assertOk()->json('tenants'))->toBe([])
        ->and($this->getJson('/api/admin/search?q=bulk')->assertOk()->json('tenants'))->toHaveCount(10)
        ->and($this->getJson('/api/admin/search?q=bulk')->json('users'))->toHaveCount(10);
    $this->getJson('/api/admin/search?q=a')->assertStatus(422);
    $this->getJson('/api/admin/search')->assertStatus(422);
});

it('search needs a signed-in admin', function (): void {
    $this->getJson('/api/admin/search?q=acme')->assertStatus(401);
});

// ---------------------------------------------------------------- failed jobs

it('lists failed jobs without payloads and secrets', function (): void {
    $uuid = supFailedJob();
    $this->asAdmin(AdminRole::Support);

    $res = $this->getJson('/api/admin/jobs/failed')->assertOk();

    expect($res->json('data.0'))->toMatchArray([
        'id' => $uuid, 'job' => 'App\\Jobs\\SendThing', 'queue' => 'default', 'connection' => 'database',
        'exception' => 'RuntimeException: boom happened in /app/Jobs/SendThing.php:12',
    ])->and($res->json('data.0.failed_at'))->not->toBeNull()
        ->and($res->getContent())->not->toContain('SECRET-PAYLOAD-BODY')->not->toContain('secret-frame')->not->toContain('Stack trace');
    $res->assertJsonStructure(['data', 'meta' => ['next_cursor', 'prev_cursor', 'limit']]);
});

it('retry_failed_job_requeues_it', function (): void {
    config(['queue.default' => 'database']);
    $uuid = supFailedJob();
    $other = supFailedJob(class: 'App\\Jobs\\Keep');
    $admin = $this->asAdmin(AdminRole::Engineer);

    $this->postJson("/api/admin/jobs/failed/{$uuid}/retry")->assertOk()->assertJsonPath('data.id', $uuid);

    $queued = supDb()->table('jobs')->get();
    expect($queued)->toHaveCount(1)
        ->and($queued[0]->queue)->toBe('default')
        ->and(json_decode($queued[0]->payload, true)['displayName'])->toBe('App\\Jobs\\SendThing')
        ->and(json_decode($queued[0]->payload, true)['attempts'])->toBe(0)
        ->and(supDb()->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(supDb()->table('failed_jobs')->where('uuid', $other)->exists())->toBeTrue();
    $audit = supAudit('failed_job.retried');
    expect($audit)->toHaveCount(1)->and($audit[0]->admin_id)->toBe($admin->id)->and($audit[0]->subject_id)->toBe($uuid);

    $this->postJson("/api/admin/jobs/failed/{$uuid}/retry")->assertStatus(404);
});

it('forgets a failed job and audits it', function (): void {
    $uuid = supFailedJob();
    $this->asAdmin(AdminRole::Owner);

    $this->deleteJson("/api/admin/jobs/failed/{$uuid}")->assertNoContent();

    expect(supDb()->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(supAudit('failed_job.forgotten'))->toHaveCount(1);
    $this->deleteJson("/api/admin/jobs/failed/{$uuid}")->assertStatus(404);
});

it('lets support view failed jobs but not retry or forget them', function (): void {
    $uuid = supFailedJob();
    $this->asAdmin(AdminRole::Support);

    $this->getJson('/api/admin/jobs/failed')->assertOk();
    $this->postJson("/api/admin/jobs/failed/{$uuid}/retry")->assertForbidden();
    $this->deleteJson("/api/admin/jobs/failed/{$uuid}")->assertForbidden();
    expect(supDb()->table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();

    $this->asAdmin(AdminRole::Sales);
    $this->getJson('/api/admin/jobs/failed')->assertForbidden();
});

// ---------------------------------------------------------------- mail log

it('mail_log_masks_recipient', function (): void {
    $f = LedgerFixture::create(slug: 'mailer');
    app(TenantContext::class)->set($f->tenantId);
    Mail::to('Jane@Acme.co')->send(new SupportProbeMail);
    app(TenantContext::class)->clear();
    Mail::to('x@y.test')->send(new SupportProbeMail);

    $row = supDb()->table('mail_log')->where('tenant_id', $f->tenantId)->first();
    expect($row)->not->toBeNull()
        ->and($row->to_hash)->toBe(hash('sha256', 'jane@acme.co'))
        ->and($row->to_masked)->toBe('J***@Acme.co')
        ->and($row->subject)->toBe('Your invoice is ready')
        ->and($row->notification)->toBe(SupportProbeMail::class)
        ->and($row->status)->toBe('sent')
        ->and(supDb()->table('mail_log')->whereNull('tenant_id')->count())->toBe(1)
        ->and(json_encode($row))->not->toContain('BODY-MUST-NOT-BE-STORED')->not->toContain('jane@acme.co');

    $this->asAdmin(AdminRole::Support);
    $res = $this->getJson('/api/admin/mail-log')->assertOk();
    expect($res->json('data'))->toHaveCount(2)
        ->and($res->getContent())->not->toContain('Jane@Acme.co')->not->toContain('jane@acme.co')->not->toContain('BODY-MUST-NOT-BE-STORED')->not->toContain('to_hash');
    $filtered = $this->getJson("/api/admin/mail-log?tenant={$f->tenantId}")->assertOk()->json('data');
    expect($filtered)->toHaveCount(1)
        ->and($filtered[0])->toMatchArray(['to' => 'J***@Acme.co', 'subject' => 'Your invoice is ready', 'notification' => SupportProbeMail::class, 'tenant_id' => $f->tenantId]);
    $this->getJson('/api/admin/mail-log?tenant=nope')->assertStatus(422);
});

it('keeps sending mail when logging it fails', function (): void {
    config(['database.default' => 'no_such_connection']);

    Mail::to('jane@acme.co')->send(new SupportProbeMail);

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(1);
});

// ---------------------------------------------------------------- webhook deliveries

it('lists a tenant webhook deliveries with the endpoint host only', function (): void {
    $f = LedgerFixture::create(slug: 'hooks');
    $other = LedgerFixture::create(slug: 'other-hooks');
    app(TenantContext::class)->set($f->tenantId);
    $endpoint = (string) Str::uuid7();
    DB::table('webhook_endpoints')->insert([
        'id' => $endpoint, 'tenant_id' => $f->tenantId, 'url' => 'https://user:pw@hooks.acme.test/in/path?token=URL-SECRET', 'secret' => 'ENDPOINT-SECRET',
        'events' => '{invoice.posted}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $delivery = (string) Str::uuid7();
    DB::table('webhook_deliveries')->insert([
        'id' => $delivery, 'tenant_id' => $f->tenantId, 'endpoint_id' => $endpoint, 'message_id' => (string) Str::uuid7(), 'event_type' => 'invoice.posted',
        'payload' => json_encode(['customer' => 'PAYLOAD-SECRET']), 'status' => 'failed', 'attempts' => 3, 'window_started_at' => now(),
        'last_attempt_at' => now(), 'response_code' => 500, 'response_snippet' => 'SNIPPET-SECRET', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(TenantContext::class)->clear();
    $this->asAdmin(AdminRole::Engineer);

    $res = $this->getJson("/api/admin/tenants/{$f->tenantId}/webhook-deliveries")->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0'))->toMatchArray([
            'id' => $delivery, 'host' => 'hooks.acme.test', 'event_type' => 'invoice.posted', 'status' => 'failed', 'attempts' => 3, 'response_code' => 500,
        ])
        ->and($res->getContent())->not->toContain('URL-SECRET')->not->toContain('PAYLOAD-SECRET')->not->toContain('ENDPOINT-SECRET')->not->toContain('SNIPPET-SECRET')->not->toContain('user:pw');
    expect($this->getJson("/api/admin/tenants/{$other->tenantId}/webhook-deliveries")->assertOk()->json('data'))->toBe([]);
    $this->getJson('/api/admin/tenants/'.Str::uuid7().'/webhook-deliveries')->assertStatus(404);
});

// ---------------------------------------------------------------- announcements

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function announcementBody(array $over = []): array
{
    return array_replace([
        'title' => 'Planned maintenance', 'body' => 'We will be down briefly on Sunday.', 'kind' => 'maintenance',
        'audience' => [], 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(),
    ], $over);
}

it('creates, updates, lists and deletes announcements, auditing each write', function (): void {
    $admin = $this->asAdmin(AdminRole::Sales);

    $id = $this->postJson('/api/admin/announcements', announcementBody())->assertCreated()->json('data.id');
    $this->putJson("/api/admin/announcements/{$id}", announcementBody(['title' => 'Changed', 'kind' => 'warning', 'audience' => ['plans' => ['growth'], 'countries' => ['KE']]]))
        ->assertOk()->assertJsonPath('data.title', 'Changed')->assertJsonPath('data.audience.plans.0', 'growth');
    expect($this->getJson('/api/admin/announcements')->assertOk()->json('data'))->toHaveCount(1);

    $this->deleteJson("/api/admin/announcements/{$id}")->assertNoContent();
    expect($this->getJson('/api/admin/announcements')->json('data'))->toBe([])
        ->and(supAudit('announcement.created'))->toHaveCount(1)
        ->and(supAudit('announcement.updated'))->toHaveCount(1)
        ->and(supAudit('announcement.deleted'))->toHaveCount(1)
        ->and(supAudit('announcement.created')[0]->admin_id)->toBe($admin->id);
});

it('validates announcements and gates them by role', function (): void {
    $this->asAdmin(AdminRole::Support);
    $this->postJson('/api/admin/announcements', announcementBody(['kind' => 'celebration']))->assertStatus(422);
    $this->postJson('/api/admin/announcements', announcementBody(['title' => '']))->assertStatus(422);
    $this->postJson('/api/admin/announcements', announcementBody(['ends_at' => now()->subDays(2)->toIso8601String()]))->assertStatus(422);
    $this->postJson('/api/admin/announcements', announcementBody(['audience' => ['lifecycles' => ['nope']]]))->assertStatus(422);
    $this->postJson('/api/admin/announcements', announcementBody(['audience' => ['countries' => ['KEN']]]))->assertStatus(422);
    $this->postJson('/api/admin/announcements', announcementBody())->assertCreated();

    foreach ([AdminRole::Finance, AdminRole::Readonly] as $role) {
        $this->asAdmin($role);
        $this->getJson('/api/admin/announcements')->assertForbidden();
        $this->postJson('/api/admin/announcements', announcementBody())->assertForbidden();
    }
});

/** @return list<string> sorted titles of the announcements the tenant's owner sees */
function supTitlesFor(object $test, array $tenant): array
{
    $test->flushSession();
    app('auth')->forgetGuards();
    app('auth')->setDefaultDriver('web');
    app(TenantContext::class)->clear();
    $rows = $test->actingAs(User::query()->findOrFail($tenant['owner']))->getJson('/api/v1/announcements')->assertOk()->json('data');
    app(TenantContext::class)->clear();
    $titles = array_column($rows, 'title');
    sort($titles);

    return $titles;
}

it('announcement_targets_plan_and_country', function (): void {
    $growth = supPlan('growth');
    $starter = supPlan('starter');
    $ke = supTenant('Kenya Growth', 'ke-growth', 'active', 'KE', $growth);
    $ug = supTenant('Uganda Starter', 'ug-starter', 'trial', 'UG', $starter);
    $this->asAdmin(AdminRole::Owner);
    $make = fn (array $over) => $this->postJson('/api/admin/announcements', announcementBody($over))->assertCreated();
    $make(['title' => 'Everyone']);
    $make(['title' => 'Growth only', 'audience' => ['plans' => ['growth']]]);
    $make(['title' => 'Kenya only', 'audience' => ['countries' => ['KE']]]);
    $make(['title' => 'Uganda trials', 'audience' => ['lifecycles' => ['trial'], 'countries' => ['UG']]]);
    $make(['title' => 'Growth in Uganda', 'audience' => ['plans' => ['growth'], 'countries' => ['UG']]]);
    $make(['title' => 'Future', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String()]);
    $make(['title' => 'Ended', 'starts_at' => now()->subDays(3)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()]);
    $make(['title' => 'Open ended', 'ends_at' => null]);
    $this->postJson('/api/admin/auth/logout');

    expect(supTitlesFor($this, $ke))->toBe(['Everyone', 'Growth only', 'Kenya only', 'Open ended'])
        ->and(supTitlesFor($this, $ug))->toBe(['Everyone', 'Open ended', 'Uganda trials']);
});

it('shows a tenant only the fields it needs', function (): void {
    $t = supTenant('Solo', 'solo');
    $this->asAdmin(AdminRole::Owner);
    $this->postJson('/api/admin/announcements', announcementBody())->assertCreated();
    $this->postJson('/api/admin/auth/logout');
    app('auth')->forgetGuards();
    app('auth')->setDefaultDriver('web');
    app(TenantContext::class)->clear();

    $row = $this->actingAs(User::query()->findOrFail($t['owner']))->getJson('/api/v1/announcements')->assertOk()->json('data.0');

    expect(array_keys($row))->toEqualCanonicalizing(['id', 'title', 'body', 'kind', 'starts_at', 'ends_at']);
});
