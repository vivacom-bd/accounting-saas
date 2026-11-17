<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Support\Actor;
use App\Support\ActorType;
use App\Support\Audit\DetachedAudit;
use App\Support\Tenancy\TenantContext;

/**
 * The tenant's own record of an impersonation (started, ended), in the tenant audit chain. Written detached on
 * `pgsql_audit` (13 §14, §18) so it survives a read-only request and any rollback, under the impersonation's tenant.
 */
final class ImpersonationAudit
{
    public function __construct(private readonly DetachedAudit $audit, private readonly TenantContext $tenant) {}

    /** The actor of an impersonated request (T6): the user's id, type admin_impersonating, never a signing grant. */
    public static function actor(Impersonation $impersonation, ?string $companyId = null, ?string $ip = null, ?string $userAgent = null, ?string $requestId = null, ?string $idempotencyKey = null): Actor
    {
        return new Actor(
            ActorType::AdminImpersonating, $impersonation->userId, $impersonation->tenantId, $companyId,
            hasSigningGrant: false, ip: $ip, userAgent: $userAgent, requestId: $requestId, idempotencyKey: $idempotencyKey,
            auditContext: $impersonation->auditContext(),
        );
    }

    /** @param array<string, mixed> $context */
    public function record(Impersonation $impersonation, string $action, array $context = [], ?string $ip = null, ?string $userAgent = null, ?string $requestId = null): void
    {
        $actor = self::actor($impersonation, ip: $ip, userAgent: $userAgent, requestId: $requestId);
        $this->tenant->run($impersonation->tenantId, fn () => $this->audit->record($actor, $action, null, $context));
    }
}
