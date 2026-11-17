<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * CSRF for /api/admin. Same check as the framework's; the readable token cookie is `XSRF-TOKEN-ADMIN` so that an
 * admin response on the shared origin (T1) never overwrites the tenant SPA's `XSRF-TOKEN`, which would break the
 * tenant session's CSRF. The admin SPA sends it back in `X-XSRF-TOKEN` as usual.
 */
final class ValidateAdminCsrfToken extends PreventRequestForgery
{
    public const string COOKIE = 'XSRF-TOKEN-ADMIN';

    /**
     * @param  Request  $request
     * @param  array<string, mixed>  $config
     */
    protected function newCookie($request, $config): Cookie
    {
        return new Cookie(
            self::COOKIE,
            $request->session()->token(),
            $this->availableAt(60 * (int) $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            (bool) ($config['partitioned'] ?? false),
        );
    }
}
