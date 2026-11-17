<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\AdminPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Support\AdminSession;
use App\Models\Admin\AdminUser;
use Illuminate\Http\JsonResponse;

/** The signed-in staff member and the abilities their role holds, for the admin SPA to shape its menus. */
final class AdminSessionController extends Controller
{
    public function show(): JsonResponse
    {
        $admin = AdminSession::user() ?? throw DomainRuleViolation::single('unauthenticated', 'Sign in to continue.');

        return response()->json(['data' => self::payload($admin)]);
    }

    /** @return array{id: string, name: string, email: string, role: string, can: list<string>} */
    public static function payload(AdminUser $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role->value,
            'can' => AdminPermissions::abilitiesFor($admin->role),
        ];
    }
}
