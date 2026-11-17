<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Actions\Admin\Auth\AttemptAdminLogin;
use App\Actions\Admin\Auth\SignOutAdmin;
use App\Contracts\Clock;
use App\Http\Controllers\Controller;
use App\Http\Support\AdminSession;
use App\Http\Support\RequestAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Staff sign-in, step one (password), sign-out and the CSRF cookie (plan Task 3). */
final class AdminLoginController extends Controller
{
    /** Sets the admin session and `XSRF-TOKEN-ADMIN` cookies for the admin SPA. */
    public function csrfCookie(): Response
    {
        return response()->noContent();
    }

    /** Password accepted → `two_factor` says whether to ask for a code (`challenge`) or set one up (`enrol`). */
    public function store(Request $request, AttemptAdminLogin $attempt, Clock $clock): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $admin = $attempt($data['email'], $data['password'], RequestAdminActor::context($request));
        AdminSession::begin($request, $admin, $clock);

        return response()->json(['two_factor' => $admin->two_factor_confirmed_at === null ? 'enrol' : 'challenge']);
    }

    public function destroy(Request $request, SignOutAdmin $signOut): Response
    {
        $admin = AdminSession::user();
        if ($admin !== null) {
            $signOut(RequestAdminActor::for($request, $admin));
        }
        AdminSession::end($request);

        return response()->noContent();
    }
}
