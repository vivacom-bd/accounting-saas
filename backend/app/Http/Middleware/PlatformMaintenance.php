<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Ops\MaintenanceState;
use App\Support\Problem\ProblemRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform maintenance mode (plan T8, Task 17): while the switch is on, the tenant API answers 503 `maintenance` with
 * the operator's message and expected end. Sign-in, the session, announcements, public pages and inbound webhooks stay
 * reachable; the admin surface is a different route group and never passes through here.
 */
final class PlatformMaintenance
{
    /** Paths (relative to the host) that keep working during maintenance. */
    public const array EXEMPT = [
        'api/v1/auth/*', 'api/v1/me', 'api/v1/session', 'api/v1/session/*',
        'api/v1/announcements', 'api/v1/announcements/*', 'api/v1/public/*', 'api/v1/webhooks/*',
        'api/stripe/webhook',
    ];

    public function __construct(private readonly MaintenanceState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::EXEMPT)) {
            /** @var Response */
            return $next($request);
        }

        $maintenance = $this->state->current();
        if (! $maintenance['enabled']) {
            /** @var Response */
            return $next($request);
        }

        $response = ProblemRenderer::http('maintenance', $maintenance['message'] ?? 'Ledgerly is undergoing maintenance. Please try again shortly.', $request);
        $response->setData([...(array) $response->getData(true), 'meta' => ['message' => $maintenance['message'], 'ends_at' => $maintenance['ends_at']]]);

        return $response;
    }
}
