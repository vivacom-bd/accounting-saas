<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;

/**
 * Whoever the request authenticated as, whatever guard did it, when it can be asked about abilities: a SPA user, or an
 * API client behind a bearer token. `$request->user()` is typed as the union of every guard's model (staff included, who
 * cannot be asked), so `can` hints go through here. Null means nobody who can hold abilities.
 */
final class RequestUser
{
    public static function principal(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
