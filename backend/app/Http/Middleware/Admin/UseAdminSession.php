<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * First in the `admin` group (decision T2): points StartSession at the admin session store, whose cookie is
 * `ledgerly_admin_session`, so an admin request never reads or writes the tenant session cookie. The `admin` guard
 * is bound to the same store (AdminServiceProvider). Config and the default guard are restored afterwards, so a
 * long-lived worker or a test making tenant requests next sees the tenant settings again.
 */
final class UseAdminSession
{
    public const string COOKIE = 'ledgerly_admin_session';

    /** Session driver name registered by AdminServiceProvider. */
    public const string DRIVER = 'admin';

    public function __construct(private readonly SessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previous = [
            'session.cookie' => config('session.cookie'),
            'session.driver' => config('session.driver'),
        ];
        $guard = config('auth.defaults.guard');

        config(['session.cookie' => self::COOKIE, 'session.driver' => self::DRIVER]);
        $this->sessions->driver(self::DRIVER)->setName(self::COOKIE);

        try {
            /** @var Response */
            return $next($request);
        } finally {
            config($previous);
            Auth::shouldUse(is_string($guard) ? $guard : 'web');
        }
    }
}
