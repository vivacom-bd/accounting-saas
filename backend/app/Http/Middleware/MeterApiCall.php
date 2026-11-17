<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\UsageMeter;
use App\Models\Organisation\ApiClient;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts one API call per authenticated API-client token request (plan Task 10): people on the SPA are not metered. It
 * counts in terminate(), after the response has been sent, so metering never slows or fails a call; a failure to count is
 * reported and swallowed. The tenant context is already cleared by then, so it is set again for the bump.
 */
final class MeterApiCall
{
    public function __construct(private readonly UsageMeter $usage, private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response */
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Not $request->user(): its type is narrowed to User, but bearer tokens resolve an ApiClient.
        $client = call_user_func($request->getUserResolver());
        if (! $client instanceof ApiClient) {
            return;
        }
        try {
            $this->tenant->run((string) $client->tenant_id, fn () => $this->usage->bump('api_calls', 1));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
