<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Models\Platform\User;
use Illuminate\Http\Request;

/** The tenant-side SPA user on the `web` guard. Staff sign in on the separate `admin` guard, so $request->user() alone is not narrowed to User. */
final class WebUser
{
    public static function of(Request $request): ?User
    {
        $user = $request->user('web');

        return $user instanceof User ? $user : null;
    }
}
