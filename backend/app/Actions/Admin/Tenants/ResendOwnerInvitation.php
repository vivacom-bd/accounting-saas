<?php

declare(strict_types=1);

namespace App\Actions\Admin\Tenants;

use App\Actions\Organisation\IssueInvitation;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Organisation\Invitation;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use App\Support\Tenancy\TenantContext;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Sends the organisation owner a fresh invitation (plan Task 7 follow-up): the tenant's open owner invitation is replaced
 * by IssueInvitation, inside the tenant UnitOfWork on the app connection, like ProvisionTenant. Refused once the tenant
 * has an active owner. Returns the new invitation's email and expiry.
 */
final class ResendOwnerInvitation
{
    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly TenantContext $tenant,
        private readonly DatabaseManager $db,
        private readonly IssueInvitation $invitations,
        private readonly AdminAuditWriter $audit,
    ) {}

    /** @return array{email: string, expires_at: DateTimeImmutable} */
    public function handle(AdminActor $admin, string $tenantId): array
    {
        $platform = AdminTransaction::connection();
        if (! $platform->table('tenants')->where('id', $tenantId)->exists()) {
            throw DomainRuleViolation::single('not_found', 'Organisation not found.');
        }
        if ($platform->table('tenant_users')->where('tenant_id', $tenantId)->where('role', 'owner')->where('status', 'active')->exists()) {
            throw DomainRuleViolation::single('owner_already_active', 'This organisation already has an active owner.');
        }

        $email = $this->db->usingConnection('pgsql', fn () => $this->tenant->run($tenantId, fn () => $this->db->connection('pgsql')
            ->table('invitations')->where('role', 'owner')->orderByDesc('created_at')->value('email')));
        if (! is_string($email)) {
            throw DomainRuleViolation::single('owner_invitation_missing', 'This organisation has no owner invitation to resend.');
        }

        $actor = new Actor(ActorType::Admin, $admin->id, $tenantId, ip: $admin->ip, userAgent: $admin->userAgent, requestId: $admin->requestId);
        /** @var Invitation $invitation */
        $invitation = $this->db->usingConnection('pgsql', fn () => $this->tenant->run($tenantId, fn () => $this->uow->run(
            $actor,
            fn (LockSet $locks, AuditBuffer $audit, Outbox $outbox): Invitation => $this->invitations->handle($actor, $audit, $email, 'owner', null, [], null, 'The Ledgerly team'),
        )));

        AdminTransaction::run(fn () => $this->audit->record(
            $admin, 'tenant.owner_invitation_resent', $tenantId, 'tenant', $tenantId, null, ['owner_email' => $invitation->email],
        ));

        return ['email' => $invitation->email, 'expires_at' => new DateTimeImmutable($invitation->expires_at->format(DATE_ATOM))];
    }
}
