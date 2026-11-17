<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixture;

/** Admin tenants list, tenant page and its writes (plan Task 7). */
beforeEach(function (): void {
    $this->withHeaders(['Accept' => 'application/json']);
});

function ownerDb(): Connection
{
    return DB::connection('pgsql_owner');
}

/** A plan with one version; returns the version id. */
function tenantPlan(string $code, string $name, int $companies = 2, int $users = 5): string
{
    $planId = (string) Str::uuid7();
    $versionId = (string) Str::uuid7();
    ownerDb()->table('plans')->insert(['id' => $planId, 'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    ownerDb()->table('plan_versions')->insert([
        'id' => $versionId, 'plan_id' => $planId, 'version' => 1, 'prices' => '{}',
        'limits' => json_encode(['companies' => $companies, 'users' => $users]), 'entitlements' => '{}', 'effective_from' => now()->subDay(),
    ]);

    return $versionId;
}

/** @return array{id: string, owner: string} */
function directoryTenant(string $name, string $slug, string $lifecycle = 'active', ?string $country = 'KE', ?string $planVersion = null, ?string $createdAt = null, ?string $ownerEmail = null): array
{
    $id = (string) Str::uuid7();
    ownerDb()->table('tenants')->insert([
        'id' => $id, 'name' => $name, 'slug' => $slug, 'lifecycle' => $lifecycle, 'country' => $country,
        'settings' => json_encode($planVersion === null ? [] : ['plan_version_id' => $planVersion]),
        'trial_ends_at' => $lifecycle === 'trial' ? now()->addDays(10) : null,
        'created_at' => $createdAt ?? now(), 'updated_at' => now(),
    ]);
    $user = (string) Str::uuid7();
    ownerDb()->table('users')->insert(['id' => $user, 'name' => "Owner of {$name}", 'email' => $ownerEmail ?? "owner@{$slug}.test", 'password' => 'x']);
    ownerDb()->table('tenant_users')->insert(['tenant_id' => $id, 'user_id' => $user, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

    return ['id' => $id, 'owner' => $user];
}

function tenantMember(string $tenantId, string $role = 'member', bool $twoFactor = false): string
{
    $id = (string) Str::uuid7();
    ownerDb()->table('users')->insert([
        'id' => $id, 'name' => 'Member '.substr($id, -4), 'email' => 'm-'.substr($id, -8).'@example.test', 'password' => 'x',
        'two_factor_secret' => $twoFactor ? 'totp-secret' : null, 'two_factor_recovery_codes' => $twoFactor ? 'codes' : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ]);
    ownerDb()->table('tenant_users')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);

    return $id;
}

function adminAudit(string $action): Collection
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', $action)->get();
}

it('requires a signed-in admin; every role may view', function (): void {
    $this->getJson('/api/admin/tenants')->assertStatus(401);

    $this->asAdmin(AdminRole::Readonly);
    $this->getJson('/api/admin/tenants')->assertOk();
});

it('lists tenants with owner, plan, counts and activity', function (): void {
    $version = tenantPlan('growth', 'Growth');
    $t = directoryTenant('Acme Ltd', 'acme', 'trial', 'KE', $version);
    tenantMember($t['id']);
    $this->asAdmin(AdminRole::Sales);

    $row = $this->getJson('/api/admin/tenants')->assertOk()->json('data.0');

    expect($row)->toMatchArray([
        'id' => $t['id'], 'name' => 'Acme Ltd', 'slug' => 'acme', 'status' => 'trial', 'country' => 'KE',
        'owner' => ['name' => 'Owner of Acme Ltd', 'email' => 'owner@acme.test'],
        'plan' => ['code' => 'growth', 'name' => 'Growth'], 'mrr' => null,
        'companies_count' => 0, 'users_count' => 2, 'last_activity_at' => null,
    ])->and($row['trial_ends_at'])->not->toBeNull()
        ->and($row['created_at'])->not->toBeNull()
        ->and($row)->not->toHaveKey('health_score');
});

it('filters by status, plan, country and q over name, slug and owner email', function (): void {
    $growth = tenantPlan('growth', 'Growth');
    $a = directoryTenant('Acme Ltd', 'acme', 'active', 'KE', $growth);
    $b = directoryTenant('Beta Traders', 'beta', 'suspended', 'UG', null, ownerEmail: 'boss@zeta-mail.test');
    $c = directoryTenant('Gamma Co', 'gamma-co', 'active', 'KE');
    $this->asAdmin(AdminRole::Support);
    $ids = fn (string $query): array => array_column($this->getJson('/api/admin/tenants?'.$query)->assertOk()->json('data'), 'id');

    expect($ids('status=suspended'))->toBe([$b['id']])
        ->and($ids('plan=growth'))->toBe([$a['id']])
        ->and($ids('country=KE&sort=name'))->toBe([$a['id'], $c['id']])
        ->and($ids('q=traders'))->toBe([$b['id']])
        ->and($ids('q=gamma-co'))->toBe([$c['id']])
        ->and($ids('q=zeta-mail'))->toBe([$b['id']])
        ->and($ids('q=%25'))->toBe([]);
    $this->getJson('/api/admin/tenants?status=bogus')->assertStatus(422);
    $this->getJson('/api/admin/tenants?sort=slug')->assertStatus(422);
});

it('pages with a stable cursor in both sort orders', function (): void {
    $names = ['Delta', 'Alpha', 'Echo', 'Charlie', 'Bravo'];
    $expectedByCreated = [];
    foreach ($names as $i => $name) {
        $t = directoryTenant($name, strtolower($name), createdAt: now()->subDays(10 - $i)->toDateTimeString());
        array_unshift($expectedByCreated, $t['id']);
    }
    $this->asAdmin();

    $walk = function (string $sort): array {
        $seen = [];
        $url = "/api/admin/tenants?limit=2&sort={$sort}";
        do {
            $page = $this->getJson($url)->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'name')];
            $next = $page->json('meta.next_cursor');
            $url = "/api/admin/tenants?limit=2&sort={$sort}&cursor={$next}";
        } while ($next !== null);

        return $seen;
    };

    $this->getJson('/api/admin/tenants?cursor=not-a-cursor')->assertStatus(422);

    expect($walk('name'))->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'])
        ->and($walk('created_at'))->toBe(['Bravo', 'Charlie', 'Echo', 'Alpha', 'Delta']);
});

it('shows the tenant page with limits, timeline in order and notes', function (): void {
    $version = tenantPlan('growth', 'Growth', companies: 3, users: 4);
    $t = directoryTenant('Acme Ltd', 'acme', 'trial', 'KE', $version);
    $this->asAdmin(AdminRole::Owner);
    $this->postJson("/api/admin/tenants/{$t['id']}/lifecycle", ['to' => 'active', 'reason' => 'Paid by bank transfer'])->assertOk();
    $this->travel(1)->minutes();
    $this->postJson("/api/admin/tenants/{$t['id']}/lifecycle", ['to' => 'past_due', 'reason' => 'Invoice overdue'])->assertOk();
    $this->postJson("/api/admin/tenants/{$t['id']}/notes", ['body' => 'Called the owner.'])->assertCreated();

    $data = $this->getJson("/api/admin/tenants/{$t['id']}")->assertOk()->json('data');

    expect($data['id'])->toBe($t['id'])
        ->and($data['status'])->toBe('past_due')
        ->and($data['limits_usage'])->toBe(['companies' => ['used' => 0, 'limit' => 3], 'users' => ['used' => 1, 'limit' => 4]])
        ->and(array_map(fn ($e) => [$e['from'], $e['to'], $e['reason']], $data['lifecycle_timeline']))->toBe([
            ['trial', 'active', 'Paid by bank transfer'], ['active', 'past_due', 'Invoice overdue'],
        ])
        ->and($data['notes'][0]['body'])->toBe('Called the owner.');

    $this->getJson('/api/admin/tenants/'.Str::uuid7())->assertStatus(404)->assertJsonPath('code', 'not_found');
    $this->getJson('/api/admin/tenants/not-a-uuid')->assertStatus(404);
});

it('lists the tenant\'s users and companies without secrets', function (): void {
    $f = LedgerFixture::create(slug: 'alpha');
    cashSale($f);
    $member = tenantMember($f->tenantId, twoFactor: true);
    app(TenantContext::class)->clear();
    $this->asAdmin(AdminRole::Readonly);

    $users = $this->getJson("/api/admin/tenants/{$f->tenantId}/users")->assertOk();
    expect(collect($users->json('data'))->firstWhere('id', $member))->toMatchArray(['org_role' => 'member', 'status' => 'active', 'two_factor' => true])
        ->and($users->getContent())->not->toContain('totp-secret')->not->toContain('codes');

    $company = $this->getJson("/api/admin/tenants/{$f->tenantId}/companies")->assertOk()->json('data.0');
    expect($company)->toMatchArray(['id' => $f->companyId, 'base_currency' => 'USD', 'posted_journals' => 1, 'open_periods' => 12, 'closed_periods' => 0]);
});

it('adds and pins notes; readonly may not', function (): void {
    $t = directoryTenant('Acme Ltd', 'acme');
    $admin = $this->asAdmin(AdminRole::Engineer);

    $note = $this->postJson("/api/admin/tenants/{$t['id']}/notes", ['body' => 'Migrating from Xero.'])->assertCreated()
        ->assertJsonPath('data.body', 'Migrating from Xero.')->assertJsonPath('data.pinned', false)
        ->assertJsonPath('data.admin.id', $admin->id)->json('data.id');
    $this->postJson("/api/admin/tenants/{$t['id']}/notes/{$note}/pin")->assertOk()->assertJsonPath('data.pinned', true);
    $this->postJson("/api/admin/tenants/{$t['id']}/notes/{$note}/pin", ['pinned' => false])->assertOk()->assertJsonPath('data.pinned', false);
    $this->postJson("/api/admin/tenants/{$t['id']}/notes", ['body' => ''])->assertStatus(422);

    $other = directoryTenant('Other', 'other');
    $this->postJson("/api/admin/tenants/{$other['id']}/notes/{$note}/pin")->assertStatus(404);

    expect(adminAudit('tenant.note_added'))->toHaveCount(1)
        ->and(adminAudit('tenant.note_pinned'))->toHaveCount(1)
        ->and(adminAudit('tenant.note_unpinned'))->toHaveCount(1)
        ->and(adminAudit('tenant.note_added')->first()->tenant_id)->toBe($t['id']);

    $this->asAdmin(AdminRole::Readonly);
    $this->postJson("/api/admin/tenants/{$t['id']}/notes", ['body' => 'x'])->assertStatus(403)->assertJsonPath('code', 'forbidden');
    $this->postJson("/api/admin/tenants/{$t['id']}/notes/{$note}/pin")->assertStatus(403);
});

it('moves the lifecycle with an event and an admin audit row', function (): void {
    $t = directoryTenant('Acme Ltd', 'acme', 'active');
    $this->asAdmin(AdminRole::Finance);

    $this->postJson("/api/admin/tenants/{$t['id']}/lifecycle", ['to' => 'past_due', 'reason' => 'Card declined'])->assertOk()
        ->assertJsonPath('data.status', 'past_due');

    expect(DB::connection('pgsql_platform')->table('tenant_lifecycle_events')->where('tenant_id', $t['id'])->count())->toBe(1)
        ->and(adminAudit('tenant.lifecycle_changed'))->toHaveCount(1);

    $this->postJson("/api/admin/tenants/{$t['id']}/lifecycle", ['to' => 'cancelled', 'reason' => 'Asked to leave'])->assertStatus(422)
        ->assertJsonPath('code', 'use_deletion_flow');
    $this->postJson("/api/admin/tenants/{$t['id']}/lifecycle", ['to' => 'trial', 'reason' => 'x'])->assertStatus(422)
        ->assertJsonPath('code', 'lifecycle_transition_invalid');
    $this->postJson("/api/admin/tenants/{$t['id']}/lifecycle", ['to' => 'active'])->assertStatus(422);

    $this->asAdmin(AdminRole::Sales);
    $this->postJson("/api/admin/tenants/{$t['id']}/lifecycle", ['to' => 'active', 'reason' => 'x'])->assertStatus(403);
});

it('resets a member\'s two-factor, only inside that tenant', function (): void {
    $t = directoryTenant('Acme Ltd', 'acme');
    $member = tenantMember($t['id'], twoFactor: true);
    $other = directoryTenant('Other', 'other');
    $this->asAdmin(AdminRole::Support);

    $this->postJson("/api/admin/tenants/{$other['id']}/users/{$member}/reset-two-factor")->assertStatus(404);
    expect(ownerDb()->table('users')->where('id', $member)->value('two_factor_secret'))->toBe('totp-secret');

    $this->postJson("/api/admin/tenants/{$t['id']}/users/{$member}/reset-two-factor")->assertOk()->assertJsonPath('data.two_factor', false);
    expect(ownerDb()->table('users')->where('id', $member)->value('two_factor_secret'))->toBeNull()
        ->and(adminAudit('tenant.user_two_factor_reset'))->toHaveCount(1)
        ->and(adminAudit('tenant.user_two_factor_reset')->first()->subject_id)->toBe($member);

    $this->postJson("/api/admin/tenants/{$t['id']}/users/{$member}/reset-two-factor")->assertStatus(422)->assertJsonPath('code', 'two_factor_not_enabled');

    $this->asAdmin(AdminRole::Readonly);
    $this->postJson("/api/admin/tenants/{$t['id']}/users/{$member}/reset-two-factor")->assertStatus(403);
});

it('disables a member but never the last active owner', function (): void {
    $t = directoryTenant('Acme Ltd', 'acme');
    $member = tenantMember($t['id']);
    $this->asAdmin(AdminRole::Owner);

    $this->postJson("/api/admin/tenants/{$t['id']}/users/{$member}/disable", ['reason' => 'Left the company'])->assertOk()
        ->assertJsonPath('data.status', 'disabled');
    expect(ownerDb()->table('tenant_users')->where('user_id', $member)->value('status'))->toBe('disabled')
        ->and(adminAudit('tenant.user_disabled'))->toHaveCount(1);

    $this->postJson("/api/admin/tenants/{$t['id']}/users/{$t['owner']}/disable", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'last_org_owner');
    $this->postJson("/api/admin/tenants/{$t['id']}/users/".Str::uuid7().'/disable', ['reason' => 'x'])->assertStatus(404);
    $this->postJson("/api/admin/tenants/{$t['id']}/users/{$member}/disable")->assertStatus(422);
});

it('shows the admin audit for one tenant', function (): void {
    $t = directoryTenant('Acme Ltd', 'acme');
    $other = directoryTenant('Other', 'other');
    $this->asAdmin(AdminRole::Owner);
    $this->postJson("/api/admin/tenants/{$t['id']}/notes", ['body' => 'one'])->assertCreated();
    $this->postJson("/api/admin/tenants/{$other['id']}/notes", ['body' => 'two'])->assertCreated();

    $rows = $this->getJson("/api/admin/tenants/{$t['id']}/audit")->assertOk()->json('data');
    expect(array_column($rows, 'action'))->toBe(['tenant.note_added'])
        ->and($rows[0]['tenant_id'])->toBe($t['id']);
});
