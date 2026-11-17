<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\ResolveTenant;
use App\Http\Support\ImpersonatedSession;
use App\Models\Platform\User;
use App\Support\Impersonation\Impersonation;
use App\Support\Impersonation\ImpersonationAudit;
use App\Support\Impersonation\ImpersonationStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The tenant side of impersonation (plan Task 14, T6). The ticket from `POST /api/admin/impersonations` is the only
 * credential: random, 48 characters, single use, valid 60 seconds, exchanged atomically by sysops.impersonation_by_ticket.
 * The admin session plays no part; this signs the tenant `web` guard in as the user, in a session of its own.
 */
final class ImpersonationLoginController extends Controller
{
    /**
     * Exchanges the ticket for a tenant session as the user (204). Whoever this browser was signed in as is signed out
     * first and the old session discarded, so an impersonation never mixes with another session; the session id is new,
     * the login is never remembered, and the session is pinned to the impersonation's tenant.
     */
    public function store(Request $request, ImpersonationStore $store, ImpersonationAudit $audit): Response
    {
        $ticket = (string) $request->validate(['ticket' => ['required', 'string', 'max:255']])['ticket'];

        $impersonation = $store->exchange($ticket);
        $user = $impersonation === null ? null : User::query()->find($impersonation->userId);
        if ($impersonation === null || $user === null) {
            throw DomainRuleViolation::single('impersonation_ticket_invalid', 'This support link is invalid, used or expired.');
        }

        ImpersonatedSession::signOut($request);
        Auth::guard('web')->login($user, false);
        $session = $request->session();
        $session->migrate(true);
        $session->put(ResolveTenant::SESSION_KEY, $impersonation->tenantId);
        $session->put(ImpersonationStore::SESSION_KEY, ['id' => $impersonation->id, 'tenant_id' => $impersonation->tenantId]);

        $audit->record($impersonation, 'impersonation.started', [
            'mode' => $impersonation->mode, 'ends_at' => $impersonation->endsAt->format(DATE_RFC3339),
        ], $request->ip(), $request->userAgent(), $request->header('X-Request-Id'));

        return response()->noContent();
    }

    /** Ends the impersonation from the banner and signs the session out (204). */
    public function destroy(Request $request, ImpersonationStore $store, ImpersonationAudit $audit): Response
    {
        $impersonation = $request->attributes->get(ApplyImpersonation::ATTRIBUTE);
        if (! $impersonation instanceof Impersonation) {
            throw DomainRuleViolation::single('forbidden', 'There is no support session to end.');
        }
        ImpersonatedSession::end($request, $impersonation, $store, $audit, 'impersonator');

        return response()->noContent();
    }
}
