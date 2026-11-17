<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Http\Support\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decision T2: an admin session ends after `admin.idle_minutes` without a request, or `admin.absolute_hours` after
 * sign-in however active it is. Either way the guard is signed out, the session dropped, and the answer is 401.
 */
final class AdminIdleTimeout
{
    public function __construct(private readonly Clock $clock) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $now = $this->clock->now()->getTimestamp();
        $loginAt = (int) $session->get(AdminSession::LOGIN_AT, 0);
        $seenAt = (int) $session->get(AdminSession::SEEN_AT, 0);

        $idle = $now - $seenAt >= 60 * (int) config('admin.idle_minutes', 30);
        $tooOld = $now - $loginAt >= 3600 * (int) config('admin.absolute_hours', 12);
        if ($idle || $tooOld) {
            AdminSession::end($request);

            throw DomainRuleViolation::single('unauthenticated', 'Your admin session has expired. Sign in again.');
        }

        $session->put(AdminSession::SEEN_AT, $now);

        /** @var Response */
        return $next($request);
    }
}
