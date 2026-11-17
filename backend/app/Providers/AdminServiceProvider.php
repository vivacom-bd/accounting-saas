<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Admin\Compliance\LegalHoldReleaseExecutor;
use App\Actions\Admin\Compliance\TenantDeleteExecutor;
use App\Http\Middleware\Admin\UseAdminSession;
use App\Http\Support\AdminSession;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The platform admin surface's plumbing (plan Task 3, decisions T2 and T9): its own session store and guard, its
 * sign-in rate limits, and keeping /api/admin out of the tenant OpenAPI document.
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Approval executors (additive: each feature appends its own tag call). Compliance, plan Task 18.
        $this->app->tag([TenantDeleteExecutor::class, LegalHoldReleaseExecutor::class], 'admin.approval_executors');
    }

    public function boot(): void
    {
        if ($this->app->environment('production') && config('admin.two_factor') === false) {
            Log::warning('ADMIN_TWO_FACTOR=false is ignored in production: staff two-factor stays mandatory.');
        }

        // A session store of its own, over the same kind of handler as tenant sessions (config admin.session_driver).
        $this->app->make(SessionManager::class)->extend(UseAdminSession::DRIVER, function (Application $app) {
            return (new SessionManager($app))->driver((string) config('admin.session_driver'))->getHandler();
        });

        // The `admin` guard reads and writes only the admin store, never the tenant one (`session.store`).
        Auth::extend('admin-session', function (Application $app, string $name, array $config): SessionGuard {
            $store = $app->make(SessionManager::class)->driver(UseAdminSession::DRIVER);
            $store->setName(UseAdminSession::COOKIE);
            $provider = Auth::createUserProvider(is_string($config['provider'] ?? null) ? $config['provider'] : null);
            if ($provider === null) {
                throw new \LogicException('The admin guard needs a user provider.');
            }
            $guard = new SessionGuard(
                $name, $provider, $store,
                timeboxDuration: (int) config('auth.timebox_duration', 200000),
                hashKey: is_string(config('app.key')) ? config('app.key') : null,
            );
            $guard->setCookieJar($app->make('cookie'));
            $guard->setDispatcher($app->make('events'));
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

            return $guard;
        });

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute(5)->by('admin-login:'.mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip()),
            Limit::perMinute(30)->by('admin-login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('admin-two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by('admin-2fa:'.(string) $request->session()->get(AdminSession::PENDING_ID, '').'|'.$request->ip()));

        RateLimiter::for('admin-set-password', fn (Request $request) => Limit::perMinute(5)->by('admin-set-password:'.$request->ip()));

        // T9: the admin API has its own document (`scramble:export --api=admin`); it is never part of the tenant one.
        Scramble::configure()->routes(fn (Route $route): bool => str_starts_with($route->uri(), 'api/')
            && $route->uri() !== 'api/admin' && ! str_starts_with($route->uri(), 'api/admin/'));
        Scramble::registerApi('admin', ['api_path' => 'api/admin', 'export_path' => 'admin-openapi.json'])
            ->routes(fn (Route $route): bool => str_starts_with($route->uri(), 'api/admin/'));
    }
}
