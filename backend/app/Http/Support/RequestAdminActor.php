<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminRequestContext;
use Illuminate\Http\Request;

/** Builds the AdminActor (or, before sign-in, the request context) for an /api/admin request. */
final class RequestAdminActor
{
    /** The signed-in staff member; 401 otherwise. */
    public static function from(Request $request): AdminActor
    {
        $admin = AdminSession::user()
            ?? throw DomainRuleViolation::single('unauthenticated', 'Sign in to continue.');

        return self::for($request, $admin);
    }

    public static function for(Request $request, AdminUser $admin): AdminActor
    {
        return self::context($request)->actorFor($admin);
    }

    public static function context(Request $request): AdminRequestContext
    {
        return new AdminRequestContext($request->ip(), $request->userAgent(), $request->headers->get('X-Request-Id'));
    }
}
