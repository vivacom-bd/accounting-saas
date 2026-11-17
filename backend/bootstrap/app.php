<?php

use App\Domain\Shared\DomainRuleViolation;
use App\Http\Middleware\Admin\AdminCan;
use App\Http\Middleware\Admin\AdminIdleTimeout;
use App\Http\Middleware\Admin\AdminIpAllowlist;
use App\Http\Middleware\Admin\RequireAdminTwoFactor;
use App\Http\Middleware\Admin\UseAdminSession;
use App\Http\Middleware\Admin\UsePlatformConnection;
use App\Http\Middleware\Admin\ValidateAdminCsrfToken;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureTenantWritable;
use App\Http\Middleware\MeterApiCall;
use App\Http\Middleware\PlatformMaintenance;
use App\Http\Middleware\RequireConfirmedPassword;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\ResolveCompany;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantFromToken;
use App\Support\Problem\ProblemRenderer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Platform admin API (T1): its own middleware group, optionally host-locked.
        then: function (): void {
            $admin = Route::middleware('admin')->prefix('api/admin')->name('admin.');
            $domain = config('admin.domain');
            if (is_string($domain) && $domain !== '') {
                $admin->domain($domain);
            }
            $admin->group(base_path('routes/admin.php'));
            // Stripe webhook (plan Task 12): outside /api/v1, no middleware group, signature-verified in the controller.
            Route::group([], base_path('routes/billing.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 13 §5. Every API request gets a request id; bearer tokens set the tenant before
        // auth:sanctum loads the (RLS-protected) API client.
        // SPA requests from SANCTUM_STATEFUL_DOMAINS get session cookies and CSRF (13 §7).
        $middleware->statefulApi();
        $middleware->api(prepend: [AssignRequestId::class, SetTenantFromToken::class], append: [MeterApiCall::class]);
        $middleware->api(prepend: [AssignRequestId::class, SetTenantFromToken::class]);
        // Platform maintenance mode (plan T8): 503 on the tenant API, except sign-in, session and public paths.
        $middleware->api(append: [PlatformMaintenance::class]);
        // Priority-sorted ahead of auth, so the 503 shows to signed-out and signed-in callers alike.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: PlatformMaintenance::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: SetTenantFromToken::class);
        // Route-model binding reads RLS tables, so tenant and company resolve first; the idempotency
        // claim needs the tenant and must precede Authorize, which sorts after SubstituteBindings.
        // Only prepends anchored on SubstituteBindings: Laravel applies appends before prepends, so an
        // "append after ResolveTenant" would find no anchor and fall to the end. Each prepend lands
        // directly before SubstituteBindings, so declaration order is the resulting order.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTenant::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveCompany::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequireIdempotencyKey::class);
        // Platform admin (plan Task 3, T2/T3): own session cookie and CSRF cookie, IP allowlist, platform DB role.
        // Protected routes add `admin.auth`; the 2FA check runs before auth:admin so a half-finished sign-in is told
        // which step is missing.
        $middleware->group('admin', [
            UseAdminSession::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateAdminCsrfToken::class,
            AssignRequestId::class,
            AdminIpAllowlist::class,
            UsePlatformConnection::class,
        ]);
        $middleware->group('admin.auth', [RequireAdminTwoFactor::class, 'auth:admin', AdminIdleTimeout::class]);
        $middleware->alias([
            'admin.can' => AdminCan::class,
            'tenant' => ResolveTenant::class,
            'company' => ResolveCompany::class,
            'tenant.writable' => EnsureTenantWritable::class,
            'idempotent' => RequireIdempotencyKey::class,
            'password.confirm' => RequireConfirmedPassword::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (DomainRuleViolation $e, Request $request) => ProblemRenderer::domain($e, $request));
        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? ProblemRenderer::validation($e, $request) : null);
        // Policies throw AuthorizationException; the handler hands callbacks its HTTP form.
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $request->is('api/*')
            ? ProblemRenderer::http('forbidden', 'You do not have permission to do this.', $request) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? ProblemRenderer::http('unauthenticated', 'Sign in to continue.', $request) : null);
        // Unknown ids, including rows another tenant owns (RLS hides them): never say which.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? ProblemRenderer::http('not_found', 'The resource does not exist.', $request) : null);
    })->create();
