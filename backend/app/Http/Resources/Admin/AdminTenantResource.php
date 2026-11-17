<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Actions\Admin\Tenants\ProvisionedTenant;

/** A tenant as the admin screens see it. Task 7 extends it with the read-side figures. */
final class AdminTenantResource
{
    /** @return array<string, mixed> */
    public static function make(ProvisionedTenant $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'status' => $t->status,
            'country' => $t->country,
            'trial_ends_at' => $t->trialEndsAt?->format(DATE_ATOM),
            'plan' => ['code' => $t->planCode, 'name' => $t->planName],
            'owner_invitation' => ['email' => $t->ownerEmail, 'expires_at' => $t->ownerInvitationExpiresAt->format(DATE_ATOM)],
        ];
    }
}
