<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;

/*
 * Production runs the database session, cache and queue drivers; the suite runs array drivers. Admin requests make the
 * platform connection the default, and the platform role has no grants on sessions/cache/jobs, so those stores must be
 * pinned to the app connection. This runs the admin sign-in with the database drivers.
 */
beforeEach(function (): void {
    config([
        'session.driver' => 'database', 'admin.session_driver' => 'database', 'cache.default' => 'database',
        'queue.default' => 'database',
    ]);
    app('cache')->forgetDriver('database');
    app('session')->forgetDrivers();
});

it('signs staff in and reads the session with database sessions, cache and rate limiting', function (): void {
    $before = DB::connection('pgsql_owner')->table('sessions')->count();
    $this->withHeaders(['Accept' => 'application/json']);
    [$admin, $secret] = $this->makeAdmin(AdminRole::Owner);

    $this->signInAdmin($admin, $secret);
    $this->getJson('/api/admin/session')->assertOk();

    expect(DB::connection('pgsql_owner')->table('sessions')->count())->toBeGreaterThan($before);
});

it('reads and writes sessions, cache, locks and jobs while the platform connection is the default', function (): void {
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_platform');
    try {
        app('session')->forgetDrivers();
        app('cache')->forgetDriver('database');
        $handler = app('session')->driver('database')->getHandler();
        expect($handler->write('probe-session', 'x'))->toBeTrue();
        app('cache')->store('database')->put('probe-key', 'v', 60);
        expect(app('cache')->store('database')->get('probe-key'))->toBe('v')
            ->and(app('cache')->store('database')->lock('probe-lock', 5)->get())->toBeTrue();
        $queue = new DatabaseQueue(DB::connection(config('queue.connections.database.connection')), 'jobs', 'default');
        expect($queue->size())->toBeInt();
    } finally {
        DB::setDefaultConnection($previous);
    }
});

it('pins the database stores to the app connection', function (): void {
    expect(config('session.connection'))->toBe('pgsql')
        ->and(config('cache.stores.database.connection'))->toBe('pgsql')
        ->and(config('cache.stores.database.lock_connection'))->toBe('pgsql')
        ->and(config('queue.connections.database.connection'))->toBe('pgsql');
});

it('still serves a tenant request with the database drivers', function (): void {
    $this->withHeaders(['Accept' => 'application/json']);
    $this->getJson('/api/v1/me')->assertStatus(401);
});
