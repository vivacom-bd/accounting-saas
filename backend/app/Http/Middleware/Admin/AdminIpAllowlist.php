<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use App\Actions\Admin\Auth\AdminIpBlocked;
use App\Contracts\Clock;
use App\Http\Support\AdminSession;
use App\Support\Admin\AdminIpRules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 403 `admin_ip_blocked` (plan Task 3). The global list (config admin.ip_allowlist) applies to every admin request,
 * signed in or not; once the staff member is known (signed in, or past the password step) their own list applies too.
 */
final class AdminIpAllowlist
{
    public function __construct(private readonly Clock $clock) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        if (! AdminIpRules::permits(AdminIpRules::global(), $ip)) {
            throw AdminIpBlocked::make();
        }

        $admin = AdminSession::user() ?? AdminSession::pending($request, $this->clock);
        if ($admin !== null && ! AdminIpRules::permits($admin->ip_allowlist, $ip)) {
            throw AdminIpBlocked::make();
        }

        /** @var Response */
        return $next($request);
    }
}
