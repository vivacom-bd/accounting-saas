<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Actions\Admin\Approvals\ConsumeApproval;
use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Admin\AdminRole;
use App\Domain\Admin\ApprovalKind;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\ImpersonationSession;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * Starts an impersonation (plan Task 14, G4/T6). Needs `impersonate.read`; the tenant must have support access open, or
 * a staff Owner overrides with a reason (flagged in the admin audit). Write mode also needs `impersonate.write.request`
 * and spends an approved `impersonate_write` request for exactly this tenant and user, asked for by this staff member
 * and approved by another (ConsumeApproval). The session lasts 60 minutes.
 *
 * Returns a single-use ticket, valid 60 seconds, that the tenant SPA exchanges at `POST /api/v1/auth/impersonate`. Only
 * its SHA-256 is stored; the ticket itself appears in this response and nowhere else (not in the audit).
 */
final class StartImpersonation
{
    public const int SESSION_MINUTES = 60;

    public const int TICKET_SECONDS = 60;

    public const int TICKET_LENGTH = 48;

    public function __construct(
        private readonly ImpersonationTarget $target,
        private readonly ConsumeApproval $consume,
        private readonly AdminAuditWriter $audit,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    /** @return array{session_id: string, ticket: string, url: string, mode: string, ends_at: string, owner_override: bool} */
    public function handle(AdminActor $actor, string $tenantId, string $userId, string $mode, string $reason, ?string $approvalId = null, bool $ownerOverride = false): array
    {
        if (! in_array($mode, ['read', 'write'], true)) {
            throw DomainRuleViolation::single('validation_failed', 'Mode is read or write.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw DomainRuleViolation::single('reason_required', 'Say why you need to impersonate this person.');
        }
        $abilities = $mode === 'write' ? ['impersonate.read', 'impersonate.write.request'] : ['impersonate.read'];
        $this->target->require($actor, $abilities, $tenantId, $userId);

        $now = $this->clock->now();
        $grant = AdminTransaction::connection()->selectOne(
            'SELECT grant_id FROM sysops.admin_support_access_open(?, ?)',
            [$tenantId, $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP')],
        );
        $grantId = is_object($grant) && is_string($grant->grant_id ?? null) ? $grant->grant_id : null;
        $override = $grantId === null;
        if ($override && ! ($ownerOverride && $actor->role === AdminRole::Owner)) {
            throw DomainRuleViolation::single('support_access_closed', 'The organisation has not opened support access.');
        }

        $ticket = Str::random(self::TICKET_LENGTH);
        $session = AdminTransaction::run(function () use ($actor, $tenantId, $userId, $mode, $reason, $approvalId, $grantId, $override, $ticket, $now): ImpersonationSession {
            $approval = null;
            if ($mode === 'write') {
                if ($approvalId === null || ! Str::isUuid($approvalId)) {
                    throw DomainRuleViolation::single('approval_required', 'Write mode needs an approved request from another staff member.');
                }
                $approval = $this->consume->handle($actor, ApprovalKind::ImpersonateWrite, $approvalId, ['tenant_id' => $tenantId, 'user_id' => $userId]);
            }

            $session = new ImpersonationSession;
            $session->forceFill([
                'id' => $this->ids->next(), 'admin_id' => $actor->id, 'tenant_id' => $tenantId, 'user_id' => $userId,
                'mode' => $mode, 'reason' => $reason, 'grant_id' => $grantId, 'owner_override' => $override,
                'approval_id' => $approval?->id, 'ticket_hash' => hash('sha256', $ticket),
                'ticket_expires_at' => $now->modify('+'.self::TICKET_SECONDS.' seconds'),
                'ends_at' => $now->modify('+'.self::SESSION_MINUTES.' minutes'), 'created_at' => $now,
            ])->save();

            $this->audit->record($actor, 'impersonation.started', $tenantId, 'impersonation_session', $session->id, null, [
                'user_id' => $userId, 'mode' => $mode, 'owner_override' => $override, 'grant_id' => $grantId,
                'approval_id' => $approval?->id, 'ends_at' => $session->ends_at->format(DATE_RFC3339),
            ], $reason);

            return $session;
        });

        return [
            'session_id' => $session->id,
            'ticket' => $ticket,
            'url' => rtrim((string) config('app.frontend_url'), '/').'/impersonate?ticket='.$ticket,
            'mode' => $mode,
            'ends_at' => $session->ends_at->setTimezone(new DateTimeZone('UTC'))->format(DATE_RFC3339),
            'owner_override' => $override,
        ];
    }
}
