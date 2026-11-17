<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Shared\DomainRuleViolation;
use App\Http\Middleware\ApplyImpersonation;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Models\Organisation\ApiClient;
use App\Models\Platform\User;
use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Idempotency\IdempotencyScope;
use App\Support\Impersonation\Impersonation;
use App\Support\Impersonation\ImpersonationAudit;
use App\Support\Signing\SigningGrants;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Builds the Actor for the request's principal in the resolved tenant (13 §4): a SPA user or an API client. */
final class RequestActor
{
    /** Whoever the request authenticated as: a person with a session, or an API client with a token. */
    public static function from(Request $request, ?string $companyId = null): Actor
    {
        // Not $request->user(): its type is narrowed to User, but bearer tokens resolve an ApiClient.
        $principal = call_user_func($request->getUserResolver());
        if ($principal instanceof ApiClient) {
            // Tokens carry no signing grant: they never sign (ADR-0004); the evidence is the token itself.
            return new Actor(
                ActorType::ApiClient, $principal->id, app(TenantContext::class)->require(), $companyId,
                ip: $request->ip(), userAgent: $request->userAgent(),
                requestId: $request->header('X-Request-Id'),
                idempotencyKey: self::idempotencyKey($request),
            );
        }

        return self::user($request, $companyId);
    }

    /** A signed-in SPA user only; API clients are refused. */
    public static function user(Request $request, ?string $companyId = null): Actor
    {
        $user = call_user_func($request->getUserResolver());
        if (! $user instanceof User) {
            throw DomainRuleViolation::single('unauthenticated', 'Sign in to continue.');
        }

        // Staff impersonating the user (T6): act as the user, typed admin_impersonating, the admin and the
        // impersonation in every audit event's context, and never a signing grant.
        $impersonation = $request->attributes->get(ApplyImpersonation::ATTRIBUTE);
        if ($impersonation instanceof Impersonation) {
            if ($impersonation->userId !== $user->id || $impersonation->tenantId !== app(TenantContext::class)->require()) {
                throw DomainRuleViolation::single('impersonation_expired', 'The support session has ended.');
            }

            return ImpersonationAudit::actor(
                $impersonation, $companyId, $request->ip(), $request->userAgent(),
                $request->header('X-Request-Id'), self::idempotencyKey($request),
            );
        }

        // A signing grant is bound to the user and this session (ADR-0004); bearer tokens have none.
        $signed = $request->hasSession() && app(SigningGrants::class)->has($user->id, $request->session()->getId());

        return new Actor(
            ActorType::User, $user->id, app(TenantContext::class)->require(), $companyId,
            hasSigningGrant: $signed,
            ip: $request->ip(), userAgent: $request->userAgent(),
            requestId: $request->header('X-Request-Id'),
            idempotencyKey: self::idempotencyKey($request),
        );
    }

    private static function idempotencyKey(Request $request): ?string
    {
        $scope = $request->attributes->get(RequireIdempotencyKey::ATTRIBUTE);

        return $scope instanceof IdempotencyScope ? $scope->key : null;
    }
}
