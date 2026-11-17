<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingScenario;

function billingSqlState(Closure $fn): ?string
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/** @return array<string, mixed> */
function billingSubInsert(string $tenant, string $version, string $status = 'active', array $over = []): array
{
    return $over + [
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'plan_version_id' => $version, 'collection' => 'offline', 'interval' => 'month',
        'currency' => 'USD', 'quantity' => 1, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ];
}

it('lets only the platform role touch the billing tables', function (): void {
    $version = BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant();
    DB::connection('pgsql_platform')->table('subscriptions')->insert(billingSubInsert($tenant, $version));

    foreach (['subscriptions', 'subscription_addons', 'subscription_events', 'stripe_prices'] as $table) {
        expect(billingSqlState(fn () => DB::connection('pgsql')->select("SELECT 1 FROM {$table}")))->toBe('42501', "{$table} app")
            ->and(billingSqlState(fn () => DB::connection('pgsql_report')->select("SELECT 1 FROM {$table}")))->toBe('42501', "{$table} report")
            ->and(billingSqlState(fn () => DB::connection('pgsql_platform')->select("SELECT 1 FROM {$table}")))->toBeNull("{$table} platform");
    }
});

it('allows one live subscription per tenant but any number of cancelled ones', function (): void {
    $version = BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant();
    $db = DB::connection('pgsql_platform');

    $db->table('subscriptions')->insert(billingSubInsert($tenant, $version, 'cancelled'));
    $db->table('subscriptions')->insert(billingSubInsert($tenant, $version, 'cancelled'));
    $db->table('subscriptions')->insert(billingSubInsert($tenant, $version, 'trialing'));

    expect(billingSqlState(fn () => $db->table('subscriptions')->insert(billingSubInsert($tenant, $version, 'active'))))->toBe('23505')
        ->and(billingSqlState(fn () => $db->table('subscriptions')->insert(billingSubInsert($tenant, $version, 'paused'))))->toBe('23505');
});

it('constrains status, collection, interval, quantity and the stripe id', function (): void {
    $version = BillingScenario::plan('pro');
    $db = DB::connection('pgsql_platform');
    $bad = fn (array $over) => billingSqlState(fn () => $db->table('subscriptions')->insert(billingSubInsert(BillingScenario::tenant(), $version, 'active', $over)));

    expect($bad(['status' => 'weird']))->toBe('23514')
        ->and($bad(['collection' => 'cheque']))->toBe('23514')
        ->and($bad(['interval' => 'week']))->toBe('23514')
        ->and($bad(['quantity' => 0]))->toBe('23514')
        ->and($bad(['custom_amount' => -1]))->toBe('23514')
        ->and($bad(['stripe_subscription_id' => 'sub_1']))->toBeNull()
        ->and($bad(['stripe_subscription_id' => 'sub_1']))->toBe('23505');
});

it('keeps the event-ordering column the webhook handler needs', function (): void {
    $version = BillingScenario::plan('pro');
    $tenant = BillingScenario::tenant();
    $db = DB::connection('pgsql_platform');
    $db->table('subscriptions')->insert(billingSubInsert($tenant, $version, 'active', ['updated_from_event_at' => '2026-03-01 00:00:00+00']));

    expect($db->table('subscriptions')->where('tenant_id', $tenant)->value('updated_from_event_at'))->not->toBeNull();
});
