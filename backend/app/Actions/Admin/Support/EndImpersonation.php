<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Contracts\Clock;
use App\Domain\Admin\AdminPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\ImpersonationSession;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/**
 * Ends an impersonation from the admin side (plan Task 14). Any staff member who may impersonate may end one: ending
 * only ever takes access away. The tenant session is refused on its next request (`impersonation_expired`). Ending an
 * ended session changes and records nothing.
 */
final class EndImpersonation
{
    public function __construct(private readonly AdminAuditWriter $audit, private readonly Clock $clock) {}

    public function handle(AdminActor $actor, string $sessionId): ImpersonationSession
    {
        if (! AdminPermissions::allows($actor->role, 'impersonate.read')) {
            throw DomainRuleViolation::single('forbidden', 'Your role cannot manage impersonation.');
        }

        return AdminTransaction::run(function () use ($actor, $sessionId): ImpersonationSession {
            $session = ImpersonationSession::query()->whereKey($sessionId)->lockForUpdate()->first()
                ?? throw DomainRuleViolation::single('not_found', 'Impersonation not found.');
            if ($session->ended_at !== null) {
                return $session;
            }

            $now = $this->clock->now();
            $session->forceFill(['ended_at' => $now])->save();
            $this->audit->record($actor, 'impersonation.ended', $session->tenant_id, 'impersonation_session', $session->id,
                ['ended_at' => null], ['ended_at' => $now->format(DATE_RFC3339), 'by' => 'staff']);

            return $session;
        });
    }
}
