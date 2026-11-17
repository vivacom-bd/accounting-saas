<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Domain\Shared\DomainRuleViolation;
use App\Http\Support\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before `auth:admin` on protected routes. Half-way through sign-in (password accepted, TOTP not yet) answers
 * 401 `admin_two_factor_required` rather than a bare `unauthenticated`, so the SPA knows which step to show. A
 * signed-in staff member who has since been deactivated, or whose 2FA was reset, is signed out on the spot.
 */
final class RequireAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = AdminSession::user();

        if ($admin === null) {
            if ($request->session()->has(AdminSession::PENDING_ID)) {
                throw DomainRuleViolation::single('admin_two_factor_required', 'Complete two-factor authentication to continue.');
            }
        } elseif (! $admin->is_active) {
            AdminSession::end($request);

            throw DomainRuleViolation::single('unauthenticated', 'Sign in to continue.');
        } elseif ($request->session()->get(AdminSession::EPOCH) !== $admin->auth_epoch) {
            // Credentials or role changed since this session signed in (or it predates the epoch): end it.
            AdminSession::end($request);

            throw DomainRuleViolation::single('unauthenticated', 'Sign in to continue.');
        } elseif ($admin->two_factor_confirmed_at === null) {
            AdminSession::end($request);

            throw DomainRuleViolation::single('admin_two_factor_required', 'Set up two-factor authentication to continue.');
        }

        /** @var Response */
        return $next($request);
    }
}
