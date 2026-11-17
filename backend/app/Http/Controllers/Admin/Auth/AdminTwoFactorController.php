<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Actions\Admin\Auth\ConfirmAdminTwoFactor;
use App\Actions\Admin\Auth\EnrolAdminTwoFactor;
use App\Actions\Admin\Auth\VerifyAdminTwoFactor;
use App\Contracts\Clock;
use App\Http\Controllers\Controller;
use App\Http\Support\AdminSession;
use App\Http\Support\RequestAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff sign-in, step two: TOTP is mandatory. A first sign-in enrols (secret + QR, then a confirming code that also
 * returns the recovery codes); later sign-ins answer a code or spend a recovery code. Only these complete sign-in.
 */
final class AdminTwoFactorController extends Controller
{
    /** @return array{secret: string, otpauth_url: string, qr_svg: string} */
    public function enrol(Request $request, EnrolAdminTwoFactor $enrol, Clock $clock): array
    {
        $admin = AdminSession::requirePending($request, $clock);

        return $enrol($admin, RequestAdminActor::context($request));
    }

    public function confirm(Request $request, ConfirmAdminTwoFactor $confirm, Clock $clock): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $admin = AdminSession::requirePending($request, $clock);

        $codes = $confirm($admin, $data['code'], RequestAdminActor::context($request));
        AdminSession::complete($request, $admin, $clock);

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Signed in: answers with the same body as GET /api/admin/session. */
    public function verify(Request $request, VerifyAdminTwoFactor $verify, Clock $clock): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:16', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);
        $admin = AdminSession::requirePending($request, $clock);

        $verify($admin, $data['code'] ?? null, $data['recovery_code'] ?? null, RequestAdminActor::context($request));
        AdminSession::complete($request, $admin, $clock);

        return response()->json(['data' => AdminSessionController::payload($admin)]);
    }
}
