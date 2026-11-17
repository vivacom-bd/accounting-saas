<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Actions\Admin\Tenants\ProvisionedTenant;
use App\Models\Platform\Tenant;

/** The canonical admin tenant plus the provisioning-only owner invitation. */
final class ProvisionedTenantResource
{
    /** @return array<string, mixed> */
    public static function make(Tenant $tenant, ProvisionedTenant $result): array
    {
        return AdminTenantResource::make($tenant) + [
            'owner_invitation' => ['email' => $result->ownerEmail, 'expires_at' => $result->ownerInvitationExpiresAt->format(DATE_ATOM)],
        ];
    }
}
