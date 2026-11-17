<?php

declare(strict_types=1);

use App\Contracts\ApprovalExecutor;
use App\Contracts\Clock;
use App\Domain\Admin\AdminRole;
use App\Domain\Admin\ApprovalKind;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Compliance: GDPR request tracking, legal hold, archive_until and deletion scheduling (plan Task 18, G7). */
beforeEach(function (): void {
    $this->withHeaders(['Accept' => 'application/json']);
});

function compTenant(string $lifecycle = 'active', bool $hold = false, ?string $archiveUntil = null): string
{
    $id = (string) Str::uuid7();
    $slug = 'c-'.substr($id, -12);
    DB::connection('pgsql_owner')->table('tenants')->insert([
        'id' => $id, 'name' => "Comp {$slug}", 'slug' => $slug, 'lifecycle' => $lifecycle, 'legal_hold' => $hold,
        'archive_until' => $archiveUntil, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** A date relative to the app's frozen clock (what the Actions see as today). */
function compDay(int $days): string
{
    return app(Clock::class)->now()->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
}

function compPlatform(): Connection
{
    return DB::connection('pgsql_platform');
}

function compDeletion(string $tenantId): ?object
{
    return compPlatform()->table('tenant_deletions')->where('tenant_id', $tenantId)->orderByDesc('created_at')->first();
}

function compTenantRow(string $tenantId): object
{
    return compPlatform()->table('tenants')->where('id', $tenantId)->first(['lifecycle', 'legal_hold', 'archive_until']);
}

/** Signs in as a new staff member with the role; returns the admin id. */
function compAs(AdminRole $role): string
{
    return test()->asAdmin($role)->id;
}

/** @return list<string> */
function compAuditActions(string $tenantId): array
{
    return compPlatform()->table('admin_audit_events')->where('tenant_id', $tenantId)->orderBy('id')->pluck('action')->all();
}

function compExecutor(ApprovalKind $kind): ApprovalExecutor
{
    foreach (app()->tagged('admin.approval_executors') as $executor) {
        if ($executor instanceof ApprovalExecutor && $executor->kind() === $kind) {
            return $executor;
        }
    }
    throw new RuntimeException("No executor for {$kind->value}");
}

function compScheduled(string $tenantId, string $scheduledFor, string $status = 'scheduled'): void
{
    $now = now();
    DB::connection('pgsql_owner')->table('tenant_deletions')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'scheduled_for' => $scheduledFor,
        'status' => $status, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

it('deletion needs a second owner and only schedules', function (): void {
    $tenant = compTenant();
    compAs(AdminRole::Support);
    $approval = $this->postJson("/api/admin/tenants/{$tenant}/deletion", ['reason' => 'Customer asked to close'])
        ->assertStatus(202)->assertJsonPath('data.kind', 'tenant_delete')->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.subject.tenant_id', $tenant)->json('data.id');

    expect(compTenantRow($tenant)->lifecycle)->toBe('active')->and(compDeletion($tenant))->toBeNull();

    $this->postJson("/api/admin/approvals/{$approval}/approve")->assertStatus(403);
    compAs(AdminRole::Finance);
    $this->postJson("/api/admin/approvals/{$approval}/approve")->assertStatus(403)->assertJsonPath('code', 'forbidden');
    expect(compTenantRow($tenant)->lifecycle)->toBe('active');

    compAs(AdminRole::Owner);
    $this->postJson("/api/admin/approvals/{$approval}/approve")->assertOk()->assertJsonPath('data.status', 'executed');

    $deletion = compDeletion($tenant);
    expect(compTenantRow($tenant)->lifecycle)->toBe('cancelled')
        ->and($deletion->status)->toBe('scheduled')
        ->and($deletion->scheduled_for)->toBe(compDay(90))
        ->and($deletion->deleted_at)->toBeNull()
        ->and($deletion->approved_by)->not->toBeNull()
        ->and(DB::connection('pgsql_owner')->table('tenants')->where('id', $tenant)->exists())->toBeTrue()
        ->and(compAuditActions($tenant))->toContain('approval.requested', 'tenant.lifecycle_changed', 'approval.executed');
});

it('the deletion executor is idempotent', function (): void {
    $tenant = compTenant();
    compAs(AdminRole::Support);
    $approval = AdminApproval::query()->findOrFail($this->postJson("/api/admin/tenants/{$tenant}/deletion", ['reason' => 'Close'])->json('data.id'));
    $owner = new AdminActor(compAs(AdminRole::Owner), AdminRole::Owner);
    $executor = compExecutor(ApprovalKind::TenantDelete);

    $executor->execute($owner, $approval);
    $executor->execute($owner, $approval);

    expect(compPlatform()->table('tenant_deletions')->where('tenant_id', $tenant)->count())->toBe(1)
        ->and(compPlatform()->table('tenant_lifecycle_events')->where('tenant_id', $tenant)->where('to_status', 'cancelled')->count())->toBe(1);
});

it('refuses a deletion for an unknown tenant, without a reason, or without the ability', function (): void {
    $tenant = compTenant();
    compAs(AdminRole::Support);
    $this->postJson('/api/admin/tenants/'.Str::uuid7().'/deletion', ['reason' => 'x'])->assertStatus(404);
    $this->postJson("/api/admin/tenants/{$tenant}/deletion", [])->assertStatus(422);
    compAs(AdminRole::Readonly);
    $this->postJson("/api/admin/tenants/{$tenant}/deletion", ['reason' => 'x'])->assertStatus(403);
});

it('legal hold blocks scheduled deletion', function (): void {
    $held = compTenant(hold: true);
    compAs(AdminRole::Support);
    $approval = $this->postJson("/api/admin/tenants/{$held}/deletion", ['reason' => 'Close'])->json('data.id');
    compAs(AdminRole::Owner);
    $this->postJson("/api/admin/approvals/{$approval}/approve")->assertOk();
    expect(compDeletion($held)->status)->toBe('blocked');

    $tenant = compTenant('cancelled');
    compScheduled($tenant, compDay(40));
    compAs(AdminRole::Readonly);
    $this->postJson("/api/admin/tenants/{$tenant}/legal-hold", ['reason' => 'Litigation'])->assertStatus(403);
    compAs(AdminRole::Support);
    $this->postJson("/api/admin/tenants/{$tenant}/legal-hold", [])->assertStatus(422);
    $this->postJson("/api/admin/tenants/{$tenant}/legal-hold", ['reason' => 'Litigation 2026-114'])->assertOk()->assertJsonPath('data.legal_hold', true);
    $this->postJson("/api/admin/tenants/{$tenant}/legal-hold", ['reason' => 'Litigation 2026-114'])->assertOk();

    expect(compTenantRow($tenant)->legal_hold)->toBeTrue()
        ->and(compDeletion($tenant)->status)->toBe('blocked')
        ->and(collect(compAuditActions($tenant))->filter(fn ($a) => $a === 'tenant.legal_hold_set')->count())->toBe(1);
});

it('releasing the hold needs approval', function (): void {
    $tenant = compTenant('cancelled', hold: true);
    $scheduledFor = compDay(30);
    compScheduled($tenant, $scheduledFor, 'blocked');

    compAs(AdminRole::Support);
    $approval = $this->deleteJson("/api/admin/tenants/{$tenant}/legal-hold", ['reason' => 'Case closed'])
        ->assertStatus(202)->assertJsonPath('data.kind', 'legal_hold_release')->assertJsonPath('data.status', 'pending')->json('data.id');
    expect(compTenantRow($tenant)->legal_hold)->toBeTrue()->and(compDeletion($tenant)->status)->toBe('blocked');

    compAs(AdminRole::Owner);
    $this->postJson("/api/admin/approvals/{$approval}/approve")->assertOk()->assertJsonPath('data.status', 'executed');
    expect(compTenantRow($tenant)->legal_hold)->toBeFalse()
        ->and(compDeletion($tenant)->status)->toBe('scheduled')
        ->and(compDeletion($tenant)->scheduled_for)->toBe($scheduledFor);
});

it('a released hold whose date already passed is pushed a week out and the audit says so; running twice is harmless', function (): void {
    $tenant = compTenant('cancelled', hold: true);
    compScheduled($tenant, compDay(-5), 'blocked');
    compAs(AdminRole::Support);
    $approval = AdminApproval::query()->findOrFail($this->deleteJson("/api/admin/tenants/{$tenant}/legal-hold", ['reason' => 'Done'])->json('data.id'));
    $owner = new AdminActor(compAs(AdminRole::Owner), AdminRole::Owner);
    $executor = compExecutor(ApprovalKind::LegalHoldRelease);

    $executor->execute($owner, $approval);
    $executor->execute($owner, $approval);

    $events = compPlatform()->table('admin_audit_events')->where('tenant_id', $tenant)->where('action', 'tenant.legal_hold_released')->get();
    expect(compDeletion($tenant)->status)->toBe('scheduled')
        ->and(compDeletion($tenant)->scheduled_for)->toBe(compDay(7))
        ->and($events)->toHaveCount(1)
        ->and($events[0]->after)->toContain('rescheduled');
});

it('releasing a hold that is not set is refused', function (): void {
    $tenant = compTenant();
    compAs(AdminRole::Support);
    $this->deleteJson("/api/admin/tenants/{$tenant}/legal-hold", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'legal_hold_not_set');
});

it('archive_until extends retention and only ever pushes a scheduled deletion later', function (): void {
    $tenant = compTenant('cancelled');
    compScheduled($tenant, compDay(30));
    $far = compDay(400);

    compAs(AdminRole::Readonly);
    $this->putJson("/api/admin/tenants/{$tenant}/archive-until", ['archive_until' => $far, 'reason' => 'Tax audit'])->assertStatus(403);
    compAs(AdminRole::Support);
    $this->putJson("/api/admin/tenants/{$tenant}/archive-until", ['archive_until' => 'soon', 'reason' => 'x'])->assertStatus(422);
    $this->putJson("/api/admin/tenants/{$tenant}/archive-until", ['archive_until' => $far, 'reason' => 'Tax audit'])->assertOk()->assertJsonPath('data.archive_until', $far);
    expect(compTenantRow($tenant)->archive_until)->toBe($far)->and(compDeletion($tenant)->scheduled_for)->toBe($far);

    $nearer = compDay(100);
    $this->putJson("/api/admin/tenants/{$tenant}/archive-until", ['archive_until' => $nearer, 'reason' => 'Shorter'])->assertOk();
    expect(compDeletion($tenant)->scheduled_for)->toBe($far);

    $this->putJson("/api/admin/tenants/{$tenant}/archive-until", ['archive_until' => null, 'reason' => 'Cleared'])->assertOk();
    expect(compTenantRow($tenant)->archive_until)->toBeNull()
        ->and(compAuditActions($tenant))->toContain('tenant.archive_until_set');
});

it('gdpr due date is 30 days and the plain email is never stored', function (): void {
    $tenant = compTenant();
    compAs(AdminRole::Support);
    $r = $this->postJson('/api/admin/compliance/gdpr-requests', [
        'kind' => 'erasure', 'tenant_id' => $tenant, 'subject_email' => '  Jane.Doe@Example.COM ',
    ])->assertCreated();

    expect($r->json('data.status'))->toBe('open')
        ->and($r->json('data.kind'))->toBe('erasure')
        ->and($r->json('data.subject_email_masked'))->toBe('j***@example.com')
        ->and($r->json('data.fulfilment'))->toBe('manual')
        ->and($r->json('data.fulfilment_note'))->toContain('4.7')
        ->and($r->json('data.due_at'))->toStartWith(compDay(30))
        ->and($r->json('data.overdue'))->toBeFalse()
        ->and(strtolower($r->getContent()))->not->toContain('jane.doe@example.com');

    $row = compPlatform()->table('gdpr_requests')->where('id', $r->json('data.id'))->first();
    expect($row->subject_email_hash)->toBe(hash('sha256', 'jane.doe@example.com'))
        ->and(strtolower((string) json_encode($row)))->not->toContain('jane.doe@example.com')
        ->and(compAuditActions($tenant))->toContain('gdpr.opened')
        ->and(strtolower((string) json_encode(compPlatform()->table('admin_audit_events')->get())))->not->toContain('jane.doe@example.com');

    $this->postJson('/api/admin/compliance/gdpr-requests', ['kind' => 'nope', 'tenant_id' => $tenant, 'subject_email' => 'a@b.test'])->assertStatus(422);
    $this->postJson('/api/admin/compliance/gdpr-requests', ['kind' => 'access', 'tenant_id' => (string) Str::uuid7(), 'subject_email' => 'a@b.test'])->assertStatus(422);
    compAs(AdminRole::Readonly);
    $this->postJson('/api/admin/compliance/gdpr-requests', ['kind' => 'access', 'tenant_id' => $tenant, 'subject_email' => 'a@b.test'])->assertStatus(403);
});

it('lists gdpr requests with filters and an overdue flag, and updates status with a note', function (): void {
    $tenant = compTenant();
    $other = compTenant();
    compAs(AdminRole::Support);
    $old = $this->postJson('/api/admin/compliance/gdpr-requests', ['kind' => 'export', 'tenant_id' => $tenant, 'subject_email' => 'a@x.test'])->json('data.id');
    $fresh = $this->postJson('/api/admin/compliance/gdpr-requests', ['kind' => 'access', 'tenant_id' => $other, 'subject_email' => 'b@x.test'])->json('data.id');
    compPlatform()->table('gdpr_requests')->where('id', $old)->update(['due_at' => app(Clock::class)->now()->modify('-1 day')]);

    $all = $this->getJson('/api/admin/compliance/gdpr-requests')->assertOk();
    $rows = collect($all->json('data'));
    expect($rows->pluck('id')->all())->toContain($old, $fresh)
        ->and($rows->firstWhere('id', $old)['overdue'])->toBeTrue()
        ->and($rows->firstWhere('id', $fresh)['overdue'])->toBeFalse()
        ->and($all->json('meta.fulfilment'))->toBe('manual');
    expect(collect($this->getJson('/api/admin/compliance/gdpr-requests?overdue=1')->json('data'))->pluck('id')->all())->toBe([$old])
        ->and(collect($this->getJson('/api/admin/compliance/gdpr-requests?kind=access')->json('data'))->pluck('id')->all())->toBe([$fresh])
        ->and(collect($this->getJson("/api/admin/compliance/gdpr-requests?tenant_id={$tenant}")->json('data'))->pluck('id')->all())->toBe([$old])
        ->and($this->getJson('/api/admin/compliance/gdpr-requests?status=done')->json('data'))->toBe([]);

    $this->patchJson("/api/admin/compliance/gdpr-requests/{$old}", ['status' => 'bogus'])->assertStatus(422);
    $this->patchJson("/api/admin/compliance/gdpr-requests/{$old}", ['status' => 'in_progress', 'note' => 'Collecting data'])
        ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.notes', 'Collecting data')->assertJsonPath('data.completed_at', null);
    $done = $this->patchJson("/api/admin/compliance/gdpr-requests/{$old}", ['status' => 'done', 'note' => 'Sent by email'])->assertOk();
    expect($done->json('data.completed_at'))->not->toBeNull()->and($done->json('data.overdue'))->toBeFalse();
    $this->patchJson("/api/admin/compliance/gdpr-requests/{$old}", ['status' => 'open'])->assertStatus(422)->assertJsonPath('code', 'gdpr_request_closed');
    $this->patchJson('/api/admin/compliance/gdpr-requests/'.Str::uuid7(), ['status' => 'done'])->assertStatus(404);
    expect(compAuditActions($tenant))->toContain('gdpr.updated');
});

it('reports the retention schedule for live deletions only', function (): void {
    $scheduled = compTenant('cancelled', archiveUntil: compDay(200));
    $blocked = compTenant('cancelled', hold: true);
    $withdrawn = compTenant('active');
    compScheduled($scheduled, compDay(12));
    compScheduled($blocked, compDay(-3), 'blocked');
    compScheduled($withdrawn, compDay(50), 'withdrawn');

    compAs(AdminRole::Readonly);
    $this->getJson('/api/admin/compliance/retention')->assertStatus(403);
    compAs(AdminRole::Support);
    $data = collect($this->getJson('/api/admin/compliance/retention')->assertOk()->json('data'));

    expect($data->pluck('tenant_id')->all())->toContain($scheduled, $blocked)->not->toContain($withdrawn);
    expect($data->firstWhere('tenant_id', $scheduled))->toMatchArray(['status' => 'scheduled', 'days_left' => 12, 'legal_hold' => false, 'archive_until' => compDay(200)])
        ->and($data->firstWhere('tenant_id', $blocked))->toMatchArray(['status' => 'blocked', 'days_left' => -3, 'legal_hold' => true, 'archive_until' => null]);
});

it('the lifecycle endpoint still sends cancelling to the deletion flow', function (): void {
    $tenant = compTenant();
    compAs(AdminRole::Owner);
    $this->postJson("/api/admin/tenants/{$tenant}/lifecycle", ['to' => 'cancelled', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'use_deletion_flow');
});
