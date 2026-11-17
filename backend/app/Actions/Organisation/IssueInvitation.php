<?php

declare(strict_types=1);

namespace App\Actions\Organisation;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Models\Organisation\Invitation;
use App\Models\Platform\Tenant;
use App\Notifications\InvitationNotification;
use App\Support\Actor;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\UnitOfWork;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * The shared mechanics of an invitation (02_AppFlow §2): replace the email's open invitation, store only the token's
 * hash, and mail the raw token after commit. The caller owns the rules about who may invite whom, and must be inside
 * a UnitOfWork. `$invitedBy` is the inviting user, or null when staff invite from the platform admin.
 */
final class IssueInvitation
{
    public const int VALID_DAYS = 7;

    public function __construct(
        private readonly UnitOfWork $uow,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /** @param  list<string>  $companyIds */
    public function handle(
        Actor $actor,
        AuditBuffer $audit,
        string $email,
        string $role,
        ?string $companyRole,
        array $companyIds,
        ?string $invitedBy,
        string $inviterName,
    ): Invitation {
        $email = mb_strtolower($email);
        $now = $this->clock->now();

        $replaced = Invitation::query()->whereRaw('lower(email) = ?', [$email])
            ->whereNull('accepted_at')->whereNull('revoked_at')->get();
        foreach ($replaced as $old) {
            $old->forceFill(['revoked_at' => $now])->save();
            $audit->add('invitation.revoked', $old, context: ['reason' => 'replaced']);
        }

        $token = Str::random(48);
        $invitation = new Invitation;
        $invitation->forceFill([
            'id' => $this->ids->next(),
            'tenant_id' => $actor->tenantId,
            'email' => $email,
            'role' => $role,
            'company_role' => $companyRole,
            'company_ids' => $companyIds === [] ? null : array_values(array_unique($companyIds)),
            'invited_by' => $invitedBy,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $now->modify('+'.self::VALID_DAYS.' days'),
        ])->save();

        $audit->add('invitation.created', $invitation, after: [
            'email' => $email, 'role' => $role, 'company_role' => $companyRole, 'company_ids' => $invitation->company_ids,
        ]);

        $organisation = (string) Tenant::query()->whereKey($actor->tenantId)->value('name');
        $this->uow->afterCommit(fn () => Notification::route('mail', $email)
            ->notify(new InvitationNotification($token, $organisation, $inviterName)));

        return $invitation;
    }
}
