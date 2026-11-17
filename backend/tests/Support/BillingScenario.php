<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Admin\AdminRole;
use App\Support\Admin\AdminActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tenants, plans and coupons for the billing tests, inserted as the roles that own them (platform tables: platform role). */
final class BillingScenario
{
    /** @param  array<string, mixed>  $attrs */
    public static function tenant(array $attrs = []): string
    {
        $id = (string) Str::uuid7();
        $slug = 't-'.substr($id, -12);
        $owner = DB::connection('pgsql_owner');
        $owner->table('tenants')->insert(['id' => $id, 'name' => 'Acme '.$slug, 'slug' => $slug] + $attrs);
        $userId = (string) Str::uuid7();
        $owner->table('users')->insert(['id' => $userId, 'name' => 'Owner', 'email' => "owner@{$slug}.test", 'password' => 'x']);
        $owner->table('tenant_users')->insert(['tenant_id' => $id, 'user_id' => $userId, 'role' => 'owner', 'status' => 'active']);

        return $id;
    }

    /**
     * A plan with one effective version; returns the version id.
     *
     * @param  array<string, array{monthly: int|null, annual: int|null}>|null  $prices
     */
    public static function plan(string $code = 'pro', ?array $prices = null, int $trialDays = 14): string
    {
        $db = DB::connection('pgsql_platform');
        $planId = (string) Str::uuid7();
        $versionId = (string) Str::uuid7();
        $db->table('plans')->insert(['id' => $planId, 'code' => $code, 'name' => ucfirst($code), 'is_active' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('plan_versions')->insert([
            'id' => $versionId, 'plan_id' => $planId, 'version' => 1,
            'prices' => json_encode($prices ?? ['USD' => ['monthly' => 4900, 'annual' => 47000], 'KES' => ['monthly' => 640000, 'annual' => 6140000], 'EUR' => ['monthly' => 4500, 'annual' => 43200]], JSON_THROW_ON_ERROR),
            'limits' => json_encode(['companies' => 1], JSON_THROW_ON_ERROR), 'entitlements' => json_encode(['budgets' => false], JSON_THROW_ON_ERROR),
            'trial_days' => $trialDays, 'effective_from' => '2026-01-01 00:00:00+00', 'created_at' => now(),
        ]);

        return $versionId;
    }

    /** @param  array<string, mixed>  $attrs */
    public static function coupon(string $code = 'WELCOME10', array $attrs = []): string
    {
        $id = (string) Str::uuid7();
        DB::connection('pgsql_platform')->table('coupons')->insert($attrs + [
            'id' => $id, 'code' => $code, 'percent' => 10, 'duration' => 'once', 'restrictions' => '{}', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public static function admin(): AdminActor
    {
        return new AdminActor((string) Str::uuid7(), AdminRole::Finance, '203.0.113.9', 'pest', 'req-billing');
    }
}
