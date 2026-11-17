<?php

declare(strict_types=1);

namespace App\Actions\Organisation;

use App\Contracts\Clock;
use App\Contracts\IdGenerator;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Organisation\SupportAccessGrant;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\UnitOfWork\AuditBuffer;
use App\Support\UnitOfWork\LockSet;
use App\Support\UnitOfWork\Outbox;
use App\Support\UnitOfWork\UnitOfWork;

/**
 * Opens support access for 24, 72 or 168 hours (plan Task 14, G4): staff may then impersonate a member of this
 * organisation. Owners and admins only, people only: never an API client and never someone being impersonated. A new
 * grant replaces the open one (it is closed as revoked by the same person); an impersonation in progress continues,
 * because support access stays open throughout.
 */
final class GrantSupportAccess
{
    public function __construct(private readonly UnitOfWork $uow, private readonly IdGenerator $ids, private readonly Clock $clock) {}

    public function handle(Actor $actor, int $hours): SupportAccessGrant
    {
        if (! in_array($hours, SupportAccessGrant::HOURS, true)) {
            throw DomainRuleViolation::single('validation_failed', 'Support access lasts 24, 72 or 168 hours.');
        }

        return $this->uow->run($actor, function (LockSet $locks, AuditBuffer $audit, Outbox $outbox) use ($actor, $hours): SupportAccessGrant {
            self::requireOrganisationAdmin($actor);
            $now = $this->clock->now();

            $open = SupportAccessGrant::query()->whereNull('revoked_at')->where('expires_at', '>', $now)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($open as $previous) {
                $previous->forceFill(['revoked_at' => $now, 'revoked_by' => (string) $actor->id])->save();
                $audit->add('support_access.revoked', $previous, after: ['revoked_at' => $now], context: ['replaced' => true]);
            }

            $grant = new SupportAccessGrant;
            $grant->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'granted_by' => (string) $actor->id,
                'hours' => $hours, 'expires_at' => $now->modify("+{$hours} hours"), 'created_at' => $now,
            ])->save();
            $audit->add('support_access.granted', $grant, after: ['hours' => $hours, 'expires_at' => $grant->expires_at]);

            return $grant->refresh();
        });
    }

    /** Owners and admins of the organisation, as themselves: not a token, not staff impersonating one of them. */
    public static function requireOrganisationAdmin(Actor $actor): void
    {
        if ($actor->type !== ActorType::User) {
            throw DomainRuleViolation::single('forbidden', 'Only organisation owners and admins, signed in as themselves, can manage support access.');
        }
        OrganisationAdmin::require($actor, 'Only organisation owners and admins can manage support access.');
    }
}
