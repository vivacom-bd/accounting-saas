<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Domain\Admin\AdminPermissions;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Support\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `admin.can:<ability>` — 403 `forbidden` unless the signed-in staff member's role holds the ability. */
final class AdminCan
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $admin = AdminSession::user();
        if ($admin === null || ! AdminPermissions::allows($admin->role, $ability)) {
            throw DomainRuleViolation::single('forbidden', 'You do not have permission to do this.');
        }

        /** @var Response */
        return $next($request);
    }
}
