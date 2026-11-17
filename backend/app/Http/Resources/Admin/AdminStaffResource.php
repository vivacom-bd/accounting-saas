<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Admin\AdminUser;

/** A staff member as the staff screen sees them. Never carries the password, TOTP secret or recovery codes. */
final class AdminStaffResource
{
    /** @return array<string, mixed> */
    public static function make(AdminUser $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'role' => $a->role->value,
            'is_active' => $a->is_active,
            'two_factor_enabled' => $a->two_factor_confirmed_at !== null,
            'ip_allowlist' => $a->ip_allowlist,
            'last_login_at' => $a->last_login_at?->format('c'),
            'invitation_pending' => $a->password_set_token_hash !== null,
            'created_at' => $a->created_at?->format('c'),
        ];
    }
}
