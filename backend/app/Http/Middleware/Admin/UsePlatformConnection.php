<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Decision T3: admin requests run on the `pgsql_platform` role by default; the previous default is restored after. */
final class UsePlatformConnection
{
    public const string CONNECTION = 'pgsql_platform';

    public function handle(Request $request, Closure $next): Response
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(self::CONNECTION);

        try {
            /** @var Response */
            return $next($request);
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
}
