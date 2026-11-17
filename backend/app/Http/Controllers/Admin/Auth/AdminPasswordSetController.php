<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Actions\Admin\Staff\SetStaffPassword;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Guest endpoint behind the invitation link: consumes the emailed token once and sets the password. */
final class AdminPasswordSetController extends Controller
{
    public function store(Request $request, SetStaffPassword $set): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $set->handle(RequestAdminActor::context($request), $data['token'], $data['password']);

        return response()->json(['data' => ['password_set' => true]]);
    }
}
