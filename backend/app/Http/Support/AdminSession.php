<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Contracts\Clock;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The admin session's own keys (plan Task 3). A correct password only records `admin.pending_id`; the `admin` guard
 * signs the staff member in after the TOTP step, with the sign-in and last-seen times the idle timeout reads.
 */
final class AdminSession
{
    public const string GUARD = 'admin';

    public const string PENDING_ID = 'admin.pending_id';

    public const string PENDING_AT = 'admin.pending_at';

    public const string LOGIN_AT = 'admin.login_at';

    public const string SEEN_AT = 'admin.seen_at';

    /** Password accepted: remember who is half-way through signing in, on a fresh session id. */
    public static function begin(Request $request, AdminUser $admin, Clock $clock): void
    {
        $session = $request->session();
        $session->regenerate();
        $session->put(self::PENDING_ID, $admin->id);
        $session->put(self::PENDING_AT, $clock->now()->getTimestamp());
    }

    /** The staff member who passed the password step, if that was recently enough and they are still active. */
    public static function pending(Request $request, Clock $clock): ?AdminUser
    {
        $session = $request->session();
        $id = $session->get(self::PENDING_ID);
        $at = (int) $session->get(self::PENDING_AT, 0);
        if (! is_string($id)) {
            return null;
        }
        if ($clock->now()->getTimestamp() - $at > 60 * (int) config('admin.pending_minutes', 10)) {
            $session->forget([self::PENDING_ID, self::PENDING_AT]);

            return null;
        }
        $admin = AdminUser::query()->find($id);

        return $admin instanceof AdminUser && $admin->is_active ? $admin : null;
    }

    /** The pending staff member, or 401 `unauthenticated` when nobody passed the password step. */
    public static function requirePending(Request $request, Clock $clock): AdminUser
    {
        return self::pending($request, $clock)
            ?? throw DomainRuleViolation::single('unauthenticated', 'Sign in to continue.');
    }

    /** TOTP passed: sign in on the admin guard (which migrates the session id) and start the session clocks. */
    public static function complete(Request $request, AdminUser $admin, Clock $clock): void
    {
        $session = $request->session();
        $session->forget([self::PENDING_ID, self::PENDING_AT]);
        Auth::guard(self::GUARD)->login($admin);
        $now = $clock->now()->getTimestamp();
        $session->put(self::LOGIN_AT, $now);
        $session->put(self::SEEN_AT, $now);
    }

    /** Sign out and drop everything the session held, including the CSRF token. */
    public static function end(Request $request): void
    {
        Auth::guard(self::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public static function user(): ?AdminUser
    {
        $user = Auth::guard(self::GUARD)->user();

        return $user instanceof AdminUser ? $user : null;
    }
}
