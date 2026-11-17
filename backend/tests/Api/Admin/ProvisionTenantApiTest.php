<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AdminApi;

uses(AdminApi::class);

function provApiPlan(DateTimeImmutable $effectiveFrom): void
{
    DB::connection('pgsql_platform')->table('plans')->insert([
        'id' => $planId = (string) Str::uuid7(), 'code' => 'growth', 'name' => 'Growth', 'is_active' => true, 'sort' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql_platform')->table('plan_versions')->insert([
        'id' => (string) Str::uuid7(), 'plan_id' => $planId, 'version' => 1,
        'prices' => json_encode(['USD' => ['monthly' => 9900, 'annual' => 99000]]), 'limits' => json_encode(['users' => 10]),
        'entitlements' => json_encode(['api_access' => true]), 'trial_days' => 21, 'effective_from' => $effectiveFrom->modify('-1 day')->format('c'), 'created_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function provBody(array $over = []): array
{
    return ['organisation_name' => 'Acme Ltd', 'country' => 'KE', 'owner_email' => 'owner@acme.test', 'owner_name' => 'Olu Owner',
        'plan_code' => 'growth', 'billing' => 'trial', ...$over];
}

it('provisions a tenant and answers 201 with the tenant resource', function (): void {
    Notification::fake();
    provApiPlan($this->clock->now());
    $this->asAdmin(AdminRole::Sales);

    $this->postJson('/api/admin/tenants', provBody(), ['Idempotency-Key' => 'prov-1'])->assertCreated()
        ->assertJsonPath('data.name', 'Acme Ltd')->assertJsonPath('data.status', 'trial')->assertJsonPath('data.country', 'KE')
        ->assertJsonPath('data.plan.code', 'growth')->assertJsonPath('data.plan.name', 'Growth')
        ->assertJsonPath('data.owner_invitation.email', 'owner@acme.test')
        ->assertJsonStructure(['data' => ['id', 'slug', 'trial_ends_at', 'owner_invitation' => ['expires_at']]]);
    Notification::assertSentOnDemand(InvitationNotification::class);
});

it('answers a retry with the same key with 200 and the same tenant', function (): void {
    Notification::fake();
    provApiPlan($this->clock->now());
    $this->asAdmin(AdminRole::Sales);

    $id = $this->postJson('/api/admin/tenants', provBody(), ['Idempotency-Key' => 'prov-2'])->assertCreated()->json('data.id');
    $this->postJson('/api/admin/tenants', provBody(), ['Idempotency-Key' => 'prov-2'])->assertOk()->assertJsonPath('data.id', $id);

    expect(DB::connection('pgsql_platform')->table('tenants')->count())->toBe(1);
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);
});

it('refuses a reused key with a different body', function (): void {
    Notification::fake();
    provApiPlan($this->clock->now());
    $this->asAdmin(AdminRole::Sales);

    $this->postJson('/api/admin/tenants', provBody(), ['Idempotency-Key' => 'prov-3'])->assertCreated();
    $this->postJson('/api/admin/tenants', provBody(['organisation_name' => 'Other']), ['Idempotency-Key' => 'prov-3'])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reuse');
});

it('requires an Idempotency-Key', function (): void {
    provApiPlan($this->clock->now());
    $this->asAdmin(AdminRole::Sales);

    $this->postJson('/api/admin/tenants', provBody())->assertStatus(428)->assertJsonPath('code', 'idempotency_key_required');
});

it('lets sales and support provision and refuses read-only roles', function (): void {
    Notification::fake();
    provApiPlan($this->clock->now());

    $this->asAdmin(AdminRole::Support);
    $this->postJson('/api/admin/tenants', provBody(), ['Idempotency-Key' => 'k-support'])->assertCreated();

    $this->asAdmin(AdminRole::Engineer);
    $this->postJson('/api/admin/tenants', provBody(['organisation_name' => 'B']), ['Idempotency-Key' => 'k-eng'])
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
});

it('validates the body', function (): void {
    provApiPlan($this->clock->now());
    $this->asAdmin(AdminRole::Sales);
    $key = ['Idempotency-Key' => 'prov-v'];

    $this->postJson('/api/admin/tenants', provBody(['country' => 'Kenya']), $key)->assertStatus(422);
    $this->postJson('/api/admin/tenants', provBody(['owner_email' => 'nope']), $key)->assertStatus(422);
    $this->postJson('/api/admin/tenants', provBody(['billing' => 'free']), $key)->assertStatus(422);
    $this->postJson('/api/admin/tenants', provBody(['trial_days' => 200]), $key)->assertStatus(422);
    $this->postJson('/api/admin/tenants', provBody(['plan_code' => 'ghost']), $key)->assertStatus(422);
    expect(DB::connection('pgsql_platform')->table('tenants')->count())->toBe(0);
});
