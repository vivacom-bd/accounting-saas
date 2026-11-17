<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\DomainRuleViolation;
use App\Http\Support\ImpersonatedSession;
use App\Models\Platform\User;
use App\Support\Impersonation\Impersonation;
use App\Support\Impersonation\ImpersonationAudit;
use App\Support\Impersonation\ImpersonationStore;
use App\Support\Impersonation\ReadOnlyConnections;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every /api/v1 request of a tenant session opened by `POST /auth/impersonate` (plan Task 14, T6, 13 §18). Runs in the
 * api group, sorted after authentication and before ResolveTenant. A request without that session marker passes
 * untouched.
 *
 * - The impersonation is read from the database on every request. Ended, past its 60 minutes, support access revoked
 *   or expired, the member no longer active, the session pointing at another user or tenant: the session is signed out
 *   and the answer is 401 `impersonation_expired`.
 * - Only tenant routes are open (plus `/me`, ending the impersonation and sign-out): switching organisation, the
 *   practice overview, profile, password and 2FA routes, invitations, sign-up are 403 `impersonation_forbidden`.
 * - Read mode: the request attribute `impersonation_read_only` (EnsureTenantWritable answers 423 on mutating methods)
 *   and the database itself: `default_transaction_read_only` on the tenant connections for the whole request, also
 *   while a streamed body is written, and reset in `finally` and again in terminate().
 * - Sign-out (`logout`) ends the impersonation instead of the user's own sign-out, which would rotate their
 *   remember-me token.
 */
final class ApplyImpersonation
{
    public const string ATTRIBUTE = 'impersonation';

    /** Routes outside a tenant an impersonated session may call. */
    private const array OUTSIDE_TENANT = ['session.me', 'auth.impersonation.end', 'logout'];

    public function __construct(
        private readonly ImpersonationStore $store,
        private readonly ReadOnlyConnections $readOnly,
        private readonly ImpersonationAudit $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->routeIs('auth.impersonate')) {
            /** @var Response */
            return $next($request);
        }
        $marker = $request->session()->get(ImpersonationStore::SESSION_KEY);
        if ($marker === null) {
            /** @var Response */
            return $next($request);
        }

        $impersonation = $this->current($request, $marker);
        if ($impersonation === null || ! $impersonation->live) {
            if ($impersonation !== null && $this->store->end($impersonation->id, $impersonation->tenantId)) {
                $this->audit->record($impersonation, 'impersonation.ended', ['by' => 'expiry'], $request->ip(), $request->userAgent(), $request->header('X-Request-Id'));
            }
            ImpersonatedSession::signOut($request);
            throw DomainRuleViolation::single('impersonation_expired', 'The support session has ended.');
        }

        if ($request->routeIs('logout')) {
            ImpersonatedSession::end($request, $impersonation, $this->store, $this->audit, 'sign_out');

            return response()->noContent();
        }
        if (! $this->allowed($request->route())) {
            throw DomainRuleViolation::single('impersonation_forbidden', 'This is not available during a support session.');
        }

        $request->attributes->set(self::ATTRIBUTE, $impersonation);
        // Ending the impersonation touches no tenant data, only its own record (pgsql_audit) and the session store.
        if (! $impersonation->readOnly() || $request->routeIs('auth.impersonation.end')) {
            /** @var Response */
            return $next($request);
        }

        $request->attributes->set('impersonation_read_only', true);
        $this->readOnly->enable();
        try {
            /** @var Response $response */
            $response = $next($request);
        } finally {
            $this->readOnly->disable();
        }

        return $this->streamReadOnly($response);
    }

    /** Safety net: a pooled connection must never stay read-only into the next request. */
    public function terminate(Request $request, Response $response): void
    {
        $this->readOnly->disable();
    }

    /** The impersonation the session points at, if the signed-in user and the session's tenant are the ones it is for. */
    private function current(Request $request, mixed $marker): ?Impersonation
    {
        $id = is_array($marker) ? ($marker['id'] ?? null) : null;
        $tenantId = is_array($marker) ? ($marker['tenant_id'] ?? null) : null;
        if (! is_string($id) || ! is_string($tenantId)) {
            return null;
        }
        $impersonation = $this->store->state($id, $tenantId);
        $user = Auth::guard('web')->user();
        if ($impersonation === null || ! $user instanceof User || $user->id !== $impersonation->userId
            || $request->session()->get(ResolveTenant::SESSION_KEY) !== $impersonation->tenantId) {
            return $impersonation === null ? null : new Impersonation(
                $impersonation->id, $impersonation->tenantId, $impersonation->userId, $impersonation->adminId,
                $impersonation->adminName, $impersonation->mode, $impersonation->endsAt, live: false,
            );
        }

        return $impersonation;
    }

    private function allowed(mixed $route): bool
    {
        if (! $route instanceof Route) {
            return false;
        }
        if (in_array($route->getName(), self::OUTSIDE_TENANT, true)) {
            return true;
        }
        $middleware = $route->gatherMiddleware();

        return in_array('tenant', $middleware, true) || in_array(ResolveTenant::class, $middleware, true);
    }

    /** A streamed body (exports) runs after this middleware has returned: keep it read-only while it is written. */
    private function streamReadOnly(Response $response): Response
    {
        if (! $response instanceof StreamedResponse) {
            return $response;
        }
        $callback = $response->getCallback();
        if ($callback === null) {
            return $response;
        }
        $readOnly = $this->readOnly;

        return $response->setCallback(function () use ($callback, $readOnly): void {
            $readOnly->enable();
            try {
                $callback();
            } finally {
                $readOnly->disable();
            }
        });
    }
}
