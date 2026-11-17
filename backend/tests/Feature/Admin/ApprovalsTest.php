<?php

declare(strict_types=1);

use App\Actions\Admin\Approvals\ConsumeApproval;
use App\Actions\Admin\Approvals\DecideApproval;
use App\Actions\Admin\Approvals\RequestApproval;
use App\Contracts\ApprovalExecutor;
use App\Domain\Admin\AdminRole;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;
use Illuminate\Support\Facades\DB;

/** Two-person approvals engine (plan Task 4): a second staff member decides, and an approval is used once. */
function approvalActor(AdminRole $role): AdminActor
{
    [$admin] = test()->makeAdmin($role);

    return new AdminActor($admin->id, $role);
}

/** @param  array<string, mixed>  $subject */
function requestApproval(AdminActor $by, ApprovalKind $kind = ApprovalKind::Refund, array $subject = ['invoice' => 'inv_1', 'amount' => 5000]): AdminApproval
{
    return app(RequestApproval::class)->handle($by, $kind, null, $subject, 'customer asked');
}

function violationCode(Closure $call): string
{
    try {
        $call();
    } catch (DomainRuleViolation $e) {
        return $e->errorCode;
    }

    return 'none';
}

function countingExecutor(ApprovalKind $kind): object
{
    $executor = new class implements ApprovalExecutor
    {
        public int $calls = 0;

        public ApprovalKind $kind = ApprovalKind::Refund;

        public function kind(): ApprovalKind
        {
            return $this->kind;
        }

        public function execute(AdminActor $approver, AdminApproval $approval): void
        {
            $this->calls++;
        }
    };
    $executor->kind = $kind;
    app()->instance('test.approval_executor.'.$kind->value, $executor);
    app()->tag(['test.approval_executor.'.$kind->value], 'admin.approval_executors');

    return $executor;
}

function adminApprovalAuditActions(): array
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->orderBy('id')->pluck('action')->all();
}

it('requester cannot approve own request', function (): void {
    $finance = approvalActor(AdminRole::Finance);
    $approval = requestApproval($finance);

    expect(violationCode(fn () => app(DecideApproval::class)->handle($finance, $approval->id, true, null)))->toBe('approval_self_decision')
        ->and($approval->fresh()?->status)->toBe('pending');
});

it('support cannot decide a refund', function (): void {
    $approval = requestApproval(approvalActor(AdminRole::Finance));

    expect(violationCode(fn () => app(DecideApproval::class)->handle(approvalActor(AdminRole::Support), $approval->id, true, null)))->toBe('forbidden')
        ->and($approval->fresh()?->status)->toBe('pending');
});

it('expired request cannot be approved', function (): void {
    $approval = requestApproval(approvalActor(AdminRole::Finance));
    $this->clock->advance('+25 hours');

    expect(violationCode(fn () => app(DecideApproval::class)->handle(approvalActor(AdminRole::Owner), $approval->id, true, null)))->toBe('approval_not_pending');
});

it('approving runs the executor once', function (): void {
    $executor = countingExecutor(ApprovalKind::Refund);
    $approval = requestApproval(approvalActor(AdminRole::Finance));
    $owner = approvalActor(AdminRole::Owner);

    $done = app(DecideApproval::class)->handle($owner, $approval->id, true, null);

    expect($executor->calls)->toBe(1)
        ->and($done->status)->toBe('executed')
        ->and($done->approved_by)->toBe($owner->id)
        ->and($done->executed_at)->not->toBeNull()
        ->and(violationCode(fn () => app(DecideApproval::class)->handle($owner, $approval->id, true, null)))->toBe('approval_not_pending')
        ->and($executor->calls)->toBe(1)
        ->and(adminApprovalAuditActions())->toBe(['approval.requested', 'approval.approved', 'approval.executed']);
});

it('rejecting records the reason and runs nothing', function (): void {
    $executor = countingExecutor(ApprovalKind::Refund);
    $approval = requestApproval(approvalActor(AdminRole::Finance));

    $done = app(DecideApproval::class)->handle(approvalActor(AdminRole::Owner), $approval->id, false, 'not covered');

    expect($done->status)->toBe('rejected')->and($done->rejection_reason)->toBe('not covered')
        ->and($executor->calls)->toBe(0)
        ->and(adminApprovalAuditActions())->toBe(['approval.requested', 'approval.rejected']);
});

it('impersonate write is approved not executed', function (): void {
    $executor = countingExecutor(ApprovalKind::ImpersonateWrite);
    $approval = requestApproval(approvalActor(AdminRole::Support), ApprovalKind::ImpersonateWrite, ['impersonation' => 'imp_1']);

    $done = app(DecideApproval::class)->handle(approvalActor(AdminRole::Owner), $approval->id, true, null);

    expect($done->status)->toBe('approved')->and($done->executed_at)->toBeNull()->and($executor->calls)->toBe(0);
});

it('consume requires matching subject', function (): void {
    $support = approvalActor(AdminRole::Support);
    $approval = requestApproval($support, ApprovalKind::ImpersonateWrite, ['impersonation' => 'imp_1', 'minutes' => 30]);
    app(DecideApproval::class)->handle(approvalActor(AdminRole::Owner), $approval->id, true, null);
    $consume = app(ConsumeApproval::class);

    expect(violationCode(fn () => $consume->handle($support, ApprovalKind::ImpersonateWrite, $approval->id, ['impersonation' => 'imp_2'])))->toBe('approval_required')
        ->and(violationCode(fn () => $consume->handle(approvalActor(AdminRole::Support), ApprovalKind::ImpersonateWrite, $approval->id, ['impersonation' => 'imp_1'])))->toBe('approval_required')
        ->and(violationCode(fn () => $consume->handle($support, ApprovalKind::Refund, $approval->id, ['impersonation' => 'imp_1'])))->toBe('approval_required');

    $used = $consume->handle($support, ApprovalKind::ImpersonateWrite, $approval->id, ['impersonation' => 'imp_1']);
    expect($used->status)->toBe('executed')->and($used->executed_at)->not->toBeNull()
        ->and(violationCode(fn () => $consume->handle($support, ApprovalKind::ImpersonateWrite, $approval->id, ['impersonation' => 'imp_1'])))->toBe('approval_required');
});

it('consume refuses an expired or pending approval', function (): void {
    $support = approvalActor(AdminRole::Support);
    $pending = requestApproval($support, ApprovalKind::ImpersonateWrite, ['impersonation' => 'imp_1']);
    $consume = app(ConsumeApproval::class);
    expect(violationCode(fn () => $consume->handle($support, ApprovalKind::ImpersonateWrite, $pending->id, ['impersonation' => 'imp_1'])))->toBe('approval_required');

    app(DecideApproval::class)->handle(approvalActor(AdminRole::Owner), $pending->id, true, null);
    $this->clock->advance('+25 hours');
    expect(violationCode(fn () => $consume->handle($support, ApprovalKind::ImpersonateWrite, $pending->id, ['impersonation' => 'imp_1'])))->toBe('approval_required');
});
