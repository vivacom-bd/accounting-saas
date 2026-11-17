<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\Impersonation\Impersonation;
use App\Support\Impersonation\ImpersonationAudit;
use App\Support\Impersonation\ImpersonationStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Signing an impersonated tenant session out (plan Task 14), without touching the user's own sessions or devices. */
final class ImpersonatedSession
{
    /**
     * Logs the web guard out of this device only (no remember-token rotation: that would sign the real user out of their
     * remembered devices, and is a write), discards the session data and id, and issues a new CSRF token.
     */
    public static function signOut(Request $request): void
    {
        Auth::guard('web')->logoutCurrentDevice();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /** Ends the impersonation in the database, records it in the tenant audit, and signs the session out. */
    public static function end(Request $request, Impersonation $impersonation, ImpersonationStore $store, ImpersonationAudit $audit, string $by): void
    {
        if ($store->end($impersonation->id, $impersonation->tenantId)) {
            $audit->record($impersonation, 'impersonation.ended', ['by' => $by], $request->ip(), $request->userAgent(), $request->header('X-Request-Id'));
        }
        self::signOut($request);
    }
}
