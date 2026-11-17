<?php

declare(strict_types=1);

namespace App\Actions\Organisation;

use App\Contracts\Clock;
use App\Models\Organisation\SupportAccessGrant;
use App\Support\Actor;
use App\Support\Impersonation\ImpersonationStore;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Closes support access (plan Task 14, G4) and ends every impersonation of this organisation in progress, including an
 * owner override, in the same transaction: the impersonator's next request is refused with `impersonation_expired`.
 * Owners and admins only, people only. Returns how many live impersonations it ended.
 */
final class RevokeSupportAccess
{
    public function __construct(private readonly UnitOfWork $uow, private readonly Clock $clock, private readonly ImpersonationStore $sessions) {}

    public function handle(Actor $actor): int
    {
        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor): int {
            GrantSupportAccess::requireOrganisationAdmin($actor);
            $now = $this->clock->now();

            $open = SupportAccessGrant::query()->whereNull('revoked_at')->where('expires_at', '>', $now)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($open as $grant) {
                $grant->forceFill(['revoked_at' => $now, 'revoked_by' => (string) $actor->id])->save();
                $audit->add('support_access.revoked', $grant, after: ['revoked_at' => $now]);
            }

            $ended = $this->sessions->endAllInTenant();
            if ($ended > 0) {
                $audit->add('impersonation.ended', null, context: ['by' => 'organisation', 'count' => $ended]);
            }

            return $ended;
        });
    }
}
