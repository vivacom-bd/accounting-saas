<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApi;
use Tests\Support\LedgerFixture;

uses(AdminApi::class);

/* Entitlement overrides set by staff (plan Task 10). */

function overrideAudit(string $action): int
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', $action)->count();
}

it('needs billing.manage to write', function (): void {
    $f = LedgerFixture::create();
    $this->asAdmin(AdminRole::Support);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/overrides/users", ['value' => 10, 'reason' => 'pilot'])->assertForbidden();
    $this->asAdmin(AdminRole::Finance);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/overrides/users", ['value' => 10, 'reason' => 'pilot'])->assertOk();
});

it('sets, lists and removes an override with a reason and an expiry, auditing each', function (): void {
    $f = LedgerFixture::create();
    $admin = $this->asAdmin(AdminRole::Finance);
    $expires = now()->addDays(30)->toIso8601String();

    $this->putJson("/api/admin/tenants/{$f->tenantId}/overrides/users", ['value' => 10, 'reason' => 'pilot extension', 'expires_at' => $expires])
        ->assertOk()->assertJsonPath('data.key', 'users')->assertJsonPath('data.value', 10)->assertJsonPath('data.reason', 'pilot extension')->assertJsonPath('data.set_by', $admin->id);
    $this->putJson("/api/admin/tenants/{$f->tenantId}/overrides/budgets", ['value' => true, 'reason' => 'demo'])->assertOk();
    $this->putJson("/api/admin/tenants/{$f->tenantId}/overrides/users", ['value' => null, 'reason' => 'unlimited for now'])->assertOk(); // replaces

    $this->getJson("/api/admin/tenants/{$f->tenantId}/overrides")->assertOk()->assertJsonCount(2, 'data');
    expect(overrideAudit('entitlement_override.set'))->toBe(3);

    $this->deleteJson("/api/admin/tenants/{$f->tenantId}/overrides/users")->assertNoContent();
    $this->getJson("/api/admin/tenants/{$f->tenantId}/overrides")->assertJsonCount(1, 'data');
    expect(overrideAudit('entitlement_override.removed'))->toBe(1);
    $this->deleteJson("/api/admin/tenants/{$f->tenantId}/overrides/users")->assertNotFound();
});

it('validates key, value, reason and expiry', function (): void {
    $f = LedgerFixture::create();
    $this->asAdmin(AdminRole::Finance);
    $url = "/api/admin/tenants/{$f->tenantId}/overrides";

    $this->putJson("$url/bogus", ['value' => 1, 'reason' => 'because'])->assertStatus(422);
    $this->putJson("$url/users", ['value' => 1])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $this->putJson("$url/users", ['value' => 'many', 'reason' => 'because'])->assertStatus(422);
    $this->putJson("$url/users", ['value' => -1, 'reason' => 'because'])->assertStatus(422);
    $this->putJson("$url/budgets", ['value' => 3, 'reason' => 'because'])->assertStatus(422);
    $this->putJson("$url/users", ['value' => 1, 'reason' => 'because', 'expires_at' => '2020-01-01T00:00:00Z'])->assertStatus(422)->assertJsonValidationErrors(['expires_at']);
    $this->putJson('/api/admin/tenants/'.Str::uuid7().'/overrides/users', ['value' => 1, 'reason' => 'because'])->assertNotFound();
});
