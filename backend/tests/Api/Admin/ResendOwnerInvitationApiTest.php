<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use App\Notifications\InvitationNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\AdminApi;

uses(AdminApi::class);

/** Provisions a tenant through the API and returns its id. */
function resendProvisioned(object $test, DateTimeImmutable $now): string
{
    DB::connection('pgsql_platform')->table('plans')->insert([
        'id' => $planId = (string) Str::uuid7(), 'code' => 'growth', 'name' => 'Growth', 'is_active' => true, 'sort' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql_platform')->table('plan_versions')->insert([
        'id' => (string) Str::uuid7(), 'plan_id' => $planId, 'version' => 1,
        'prices' => json_encode(['USD' => ['monthly' => 9900]]), 'limits' => json_encode(['users' => 10]),
        'entitlements' => json_encode([]), 'trial_days' => 21, 'effective_from' => $now->modify('-1 day')->format('c'), 'created_at' => now(),
    ]);

    return $test->postJson('/api/admin/tenants', [
        'organisation_name' => 'Acme Ltd', 'country' => 'KE', 'owner_email' => 'owner@acme.test', 'owner_name' => 'Olu Owner',
        'plan_code' => 'growth', 'billing' => 'trial',
    ], ['Idempotency-Key' => 'resend-1'])->assertCreated()->json('data.id');
}

/** @return list<object> */
function resendInvitations(string $tenantId): array
{
    return app(TenantContext::class)->run($tenantId, fn () => DB::connection('pgsql')->table('invitations')->orderBy('created_at')->get()->all());
}

it('resends the owner invitation: the open one is replaced and a fresh one is mailed', function (): void {
    Notification::fake();
    $this->asAdmin(AdminRole::Sales);
    $id = resendProvisioned($this, $this->clock->now());
    $first = resendInvitations($id)[0];

    $this->postJson("/api/admin/tenants/{$id}/resend-owner-invitation")->assertOk()
        ->assertJsonPath('data.owner_invitation.email', 'owner@acme.test')->assertJsonStructure(['data' => ['owner_invitation' => ['expires_at']]]);

    $all = resendInvitations($id);
    expect($all)->toHaveCount(2);
    $old = collect($all)->firstWhere('id', $first->id);
    expect($old->revoked_at)->not->toBeNull();
    $new = collect($all)->firstWhere(fn ($i) => $i->id !== $first->id);
    expect($new->revoked_at)->toBeNull()->and($new->role)->toBe('owner')->and($new->email)->toBe('owner@acme.test');
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
    $audit = DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'tenant.owner_invitation_resent')->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->tenant_id)->toBe($id);
});

it('refuses when the tenant already has an active owner', function (): void {
    Notification::fake();
    $this->asAdmin(AdminRole::Sales);
    $id = resendProvisioned($this, $this->clock->now());
    $user = (string) Str::uuid7();
    DB::connection('pgsql_owner')->table('users')->insert(['id' => $user, 'name' => 'Olu', 'email' => 'owner@acme.test', 'password' => 'x']);
    DB::connection('pgsql_owner')->table('tenant_users')->insert(['tenant_id' => $id, 'user_id' => $user, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

    $this->postJson("/api/admin/tenants/{$id}/resend-owner-invitation")->assertStatus(409)->assertJsonPath('code', 'owner_already_active');
    expect(resendInvitations($id))->toHaveCount(1);
});

it('needs tenants.provision and a known tenant', function (): void {
    Notification::fake();
    $this->asAdmin(AdminRole::Sales);
    $id = resendProvisioned($this, $this->clock->now());
    $this->postJson('/api/admin/tenants/'.Str::uuid7().'/resend-owner-invitation')->assertStatus(404);

    $this->asAdmin(AdminRole::Readonly);
    $this->postJson("/api/admin/tenants/{$id}/resend-owner-invitation")->assertStatus(403);
});
