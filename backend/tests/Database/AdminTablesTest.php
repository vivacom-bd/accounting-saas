<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function adminUserRow(string $email = 'ops@example.test'): array
{
    return [
        'id' => (string) Str::uuid7(), 'email' => $email, 'name' => 'Ops', 'password' => 'x', 'role' => 'owner',
        'created_at' => now(), 'updated_at' => now(),
    ];
}

function sqlState(Closure $fn): ?string
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

it('lets the platform role write admin tables and not the app role', function () {
    DB::connection('pgsql_platform')->table('admin_users')->insert(adminUserRow());
    expect(DB::connection('pgsql_platform')->table('admin_users')->count())->toBe(1);

    expect(sqlState(fn () => DB::connection('pgsql')->table('admin_users')->insert(adminUserRow('b@example.test'))))->toBe('42501')
        ->and(sqlState(fn () => DB::connection('pgsql_report')->table('admin_users')->count()))->toBe('42501');
});

it('refuses update and delete on admin audit events', function () {
    $platform = DB::connection('pgsql_platform');
    $platform->statement(
        "INSERT INTO admin_audit_events (admin_id, action, subject_type, occurred_at, canonical, hash) VALUES (NULL, 'x', 't', now(), '{}', repeat('a', 64))"
    );

    expect(sqlState(fn () => $platform->statement("UPDATE admin_audit_events SET action = 'y'")))->toBe('LG010')
        ->and(sqlState(fn () => $platform->statement('DELETE FROM admin_audit_events')))->toBe('LG010');
});

it('gives the platform role no access to tenant tables', function () {
    expect(sqlState(fn () => DB::connection('pgsql_platform')->select('SELECT 1 FROM journals')))->toBe('42501');
});

it('lets the platform role update only lifecycle columns of tenants', function () {
    $id = (string) Str::uuid7();
    DB::connection('pgsql_owner')->table('tenants')->insert(['id' => $id, 'name' => 'T', 'slug' => 't-'.substr($id, -8)]);
    $platform = DB::connection('pgsql_platform');

    expect($platform->table('tenants')->where('id', $id)->update(['lifecycle' => 'suspended', 'country' => 'KE', 'lifecycle_since' => now(), 'delinquent_since' => now()]))->toBe(1)
        ->and(sqlState(fn () => $platform->table('tenants')->where('id', $id)->update(['name' => 'hacked'])))->toBe('42501');
});
