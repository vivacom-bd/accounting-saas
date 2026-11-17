<?php

declare(strict_types=1);

use App\Actions\Admin\Approvals\RequestApproval;
use App\Domain\Admin\AdminRole;
use App\Domain\Admin\ApprovalKind;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use Illuminate\Support\Facades\DB;

/** Approvals API (plan Task 4): list, approve, reject. */
beforeEach(function (): void {
    $this->withHeaders(['Accept' => 'application/json']);
});

/** @param  array<string, mixed>  $subject */
function pendingApproval(object $test, ApprovalKind $kind = ApprovalKind::PlanMigration, array $subject = ['plan' => 'pro']): AdminApproval
{
    [$requester] = $test->makeAdmin(AdminRole::Finance);

    return app(RequestApproval::class)->handle(new AdminActor($requester->id, AdminRole::Finance), $kind, null, $subject, 'upgrade');
}

/** @return list<string> */
function apiAuditActions(): array
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->orderBy('id')->pluck('action')->all();
}

it('lists approvals filtered by status', function (): void {
    $pending = pendingApproval($this);
    $rejected = pendingApproval($this, ApprovalKind::Refund, ['invoice' => 'x']);
    $this->asAdmin(AdminRole::Owner);
    $this->postJson("/api/admin/approvals/{$rejected->id}/reject", ['reason' => 'no'])->assertOk();

    $all = $this->getJson('/api/admin/approvals')->assertOk()->json('data');
    expect($all)->toHaveCount(2);
    $list = $this->getJson('/api/admin/approvals?status=pending')->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.id'))->toBe($pending->id)
        ->and($list->json('data.0.kind'))->toBe('plan_migration')
        ->and($list->json('data.0.status'))->toBe('pending')
        ->and($list->json('data.0.subject'))->toBe(['plan' => 'pro'])
        ->and($this->getJson('/api/admin/approvals?status=rejected')->json('data'))->toHaveCount(1);
    $this->getJson('/api/admin/approvals?status=bogus')->assertStatus(422);
});

it('approves and rejects through the api and audits each', function (): void {
    $a = pendingApproval($this, ApprovalKind::ImpersonateWrite, ['impersonation' => 'i1']);
    $b = pendingApproval($this, ApprovalKind::Refund, ['invoice' => 'x']);
    $owner = $this->asAdmin(AdminRole::Owner);

    $this->postJson("/api/admin/approvals/{$a->id}/approve")->assertOk()
        ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $owner->id);
    $this->postJson("/api/admin/approvals/{$b->id}/reject", ['reason' => 'duplicate'])->assertOk()
        ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'duplicate');

    expect(array_values(array_filter(apiAuditActions(), fn (string $a): bool => str_starts_with($a, 'approval.'))))->toBe(['approval.requested', 'approval.requested', 'approval.approved', 'approval.rejected']);
    $this->postJson("/api/admin/approvals/{$a->id}/approve")->assertStatus(409)->assertJsonPath('code', 'approval_not_pending');
    $this->postJson('/api/admin/approvals/'.Str::uuid7().'/approve')->assertStatus(404);
    $this->postJson('/api/admin/approvals/not-a-uuid/approve')->assertStatus(404);
});

it('requires a reason to reject', function (): void {
    $a = pendingApproval($this);
    $this->asAdmin(AdminRole::Owner);

    $this->postJson("/api/admin/approvals/{$a->id}/reject", [])->assertStatus(422);
});

it('refuses self decision and unauthorised roles over the api', function (): void {
    $finance = $this->asAdmin(AdminRole::Finance);
    $mine = app(RequestApproval::class)->handle(
        new AdminActor($finance->id, AdminRole::Finance), ApprovalKind::Refund, null, ['invoice' => 'y'], 'why',
    );

    $this->postJson("/api/admin/approvals/{$mine->id}/approve")->assertStatus(403)->assertJsonPath('code', 'approval_self_decision');

    $this->asAdmin(AdminRole::Support);
    $this->postJson("/api/admin/approvals/{$mine->id}/approve")->assertStatus(403)->assertJsonPath('code', 'forbidden');
});

it('requires sign in', function (): void {
    $this->getJson('/api/admin/approvals')->assertStatus(401);
});
