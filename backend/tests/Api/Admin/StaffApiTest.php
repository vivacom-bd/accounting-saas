<?php

declare(strict_types=1);

use App\Actions\Admin\Staff\DeactivateStaff;
use App\Actions\Admin\Staff\ResetStaffTwoFactor;
use App\Actions\Admin\Staff\UpdateStaff;
use App\Domain\Admin\AdminRole;
use App\Models\Admin\AdminUser;
use App\Notifications\AdminInvitation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

/** Staff and security API (plan Task 8): staff management, set-password invitations, the audit viewer. */
beforeEach(function (): void {
    $this->withHeaders(['Accept' => 'application/json']);
});

/** @return array<string, mixed> the admin session store's data, to resume that sign-in later */
function adminSessionData(): array
{
    return app('session')->driver('admin')->all();
}

/** Resumes a sign-in captured earlier, as a browser holding that session's cookie would. */
function restoreAdminSession(array $data): void
{
    app('auth')->forgetGuards();
    $store = app('session')->driver('admin');
    $store->flush();
    $store->replace($data);
}

/** @return list<string> */
function staffAuditActions(): array
{
    return DB::connection('pgsql_platform')->table('admin_audit_events')->orderBy('id')->pluck('action')->all();
}

/** Invites a staff member as the signed-in owner and returns [id, raw token]. */
function inviteStaff(object $test, string $email = 'new.hire@ledgerly.test', string $role = 'finance'): array
{
    Notification::fake();
    $id = $test->postJson('/api/admin/staff', ['email' => $email, 'name' => 'New Hire', 'role' => $role])
        ->assertCreated()->json('data.id');
    $token = null;
    Notification::assertSentOnDemand(AdminInvitation::class, function (AdminInvitation $n) use (&$token): bool {
        $token = $n->token;

        return true;
    });

    return [$id, (string) $token];
}

it('owner_invites_staff_who_sets_password_then_enrols', function (): void {
    $this->asAdmin(AdminRole::Owner);
    [$id, $token] = inviteStaff($this);

    $row = DB::connection('pgsql_platform')->table('admin_users')->where('id', $id)->first();
    expect($row->password_set_token_hash)->toBe(hash('sha256', $token))
        ->and($row->password_set_token_hash)->not->toBe($token)
        ->and($row->password_set_expires_at)->not->toBeNull();
    Notification::assertSentOnDemand(AdminInvitation::class, fn (AdminInvitation $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'new.hire@ledgerly.test'
        && str_contains($n->url(), '/admin/set-password?token='.$token));

    $this->postJson('/api/admin/auth/logout');
    $this->postJson('/api/admin/auth/set-password', ['token' => $token, 'password' => 'Brand-New-Pass-42'])->assertOk();
    // One use only.
    $this->postJson('/api/admin/auth/set-password', ['token' => $token, 'password' => 'Another-Pass-4242'])->assertStatus(422)->assertJsonPath('code', 'invalid_token');

    $this->postJson('/api/admin/auth/login', ['email' => 'new.hire@ledgerly.test', 'password' => 'Brand-New-Pass-42'])
        ->assertOk()->assertExactJson(['two_factor' => 'enrol']);

    expect(staffAuditActions())->toContain('staff.invited', 'staff.password_set');
});

it('rejects_expired_unknown_and_weak_set_password_requests', function (): void {
    $this->asAdmin(AdminRole::Owner);
    [, $token] = inviteStaff($this);
    $this->postJson('/api/admin/auth/logout');

    $this->postJson('/api/admin/auth/set-password', ['token' => 'nope', 'password' => 'Brand-New-Pass-42'])->assertStatus(422)->assertJsonPath('code', 'invalid_token');
    $this->postJson('/api/admin/auth/set-password', ['token' => $token, 'password' => 'short'])->assertStatus(422);

    $this->clock->advance('+25 hours');
    $this->postJson('/api/admin/auth/set-password', ['token' => $token, 'password' => 'Brand-New-Pass-42'])->assertStatus(422)->assertJsonPath('code', 'invalid_token');
});

it('throttles_set_password_to_five_a_minute', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/admin/auth/set-password', ['token' => 'nope', 'password' => 'Brand-New-Pass-42'])->assertStatus(422);
    }
    $this->postJson('/api/admin/auth/set-password', ['token' => 'nope', 'password' => 'Brand-New-Pass-42'])->assertStatus(429);
});

it('requires_staff_manage_for_every_staff_route', function (): void {
    [$target] = $this->makeAdmin(AdminRole::Support);
    $this->asAdmin(AdminRole::Finance);

    $this->getJson('/api/admin/staff')->assertStatus(403);
    $this->postJson('/api/admin/staff', ['email' => 'x@ledgerly.test', 'name' => 'X', 'role' => 'sales'])->assertStatus(403);
    $this->patchJson("/api/admin/staff/{$target->id}", ['name' => 'Y'])->assertStatus(403);
    $this->postJson("/api/admin/staff/{$target->id}/deactivate")->assertStatus(403);
    $this->postJson("/api/admin/staff/{$target->id}/reset-two-factor")->assertStatus(403);
});

it('lists_staff_without_secrets', function (): void {
    $this->asAdmin(AdminRole::Owner);
    $this->makeAdmin(AdminRole::Readonly);

    $rows = $this->getJson('/api/admin/staff')->assertOk()->json('data');
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toHaveKeys(['id', 'name', 'email', 'role', 'is_active', 'two_factor_enabled', 'ip_allowlist', 'last_login_at', 'invitation_pending'])
        ->and($rows[0])->not->toHaveKeys(['password', 'two_factor_secret', 'two_factor_recovery_codes']);
});

it('rejects_a_duplicate_email_and_a_bad_role', function (): void {
    $owner = $this->asAdmin(AdminRole::Owner);
    $this->postJson('/api/admin/staff', ['email' => strtoupper($owner->email), 'name' => 'Dup', 'role' => 'sales'])
        ->assertStatus(409)->assertJsonPath('code', 'email_taken');
    $this->postJson('/api/admin/staff', ['email' => 'a@ledgerly.test', 'name' => 'A', 'role' => 'god'])->assertStatus(422);
});

it('updates_staff_and_audits_before_and_after', function (): void {
    [$target] = $this->makeAdmin(AdminRole::Support);
    $this->asAdmin(AdminRole::Owner);

    $this->patchJson("/api/admin/staff/{$target->id}", ['role' => 'engineer', 'name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.role', 'engineer')->assertJsonPath('data.name', 'Renamed');

    $event = DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'staff.updated')->first();
    expect(json_decode($event->before, true)['role'])->toBe('support')
        ->and(json_decode($event->after, true)['role'])->toBe('engineer');
});

it('last_owner_cannot_be_demoted', function (): void {
    $owner = $this->asAdmin(AdminRole::Owner);

    $this->patchJson("/api/admin/staff/{$owner->id}", ['role' => 'finance'])->assertStatus(409)->assertJsonPath('code', 'last_staff_owner');
    $this->postJson("/api/admin/staff/{$owner->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'last_staff_owner');

    // A second active owner makes the first demotable; the second is then the last.
    [$second] = $this->makeAdmin(AdminRole::Owner);
    $this->patchJson("/api/admin/staff/{$owner->id}", ['role' => 'finance'])->assertOk();
    expect(AdminUser::query()->find($owner->id)->role)->toBe(AdminRole::Finance);

    // The demoted owner no longer holds staff.manage; sign back in as the second owner.
    $secret = (string) AdminUser::query()->find($second->id)->two_factor_secret;
    $this->signInAdmin($second, $secret);
    $this->postJson("/api/admin/staff/{$second->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'last_staff_owner');
});

it('an_inactive_owner_does_not_count_as_an_owner', function (): void {
    $owner = $this->asAdmin(AdminRole::Owner);
    [$other] = $this->makeAdmin(AdminRole::Owner);
    $other->forceFill(['is_active' => false])->save();

    $this->postJson("/api/admin/staff/{$owner->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'last_staff_owner');
});

it('ip_allowlist_validates_cidrs', function (): void {
    [$target] = $this->makeAdmin(AdminRole::Support);
    $this->asAdmin(AdminRole::Owner);

    foreach ([['not-an-ip'], ['10.0.0.0/33'], ['2001:db8::/129'], ['10.0.0.1/abc'], [str_repeat('1', 5)]] as $bad) {
        $this->patchJson("/api/admin/staff/{$target->id}", ['ip_allowlist' => $bad])->assertStatus(422);
    }
    $this->patchJson("/api/admin/staff/{$target->id}", ['ip_allowlist' => ['10.0.0.0/8', '192.0.2.7', '2001:db8::/32']])
        ->assertOk()->assertJsonPath('data.ip_allowlist', ['10.0.0.0/8', '192.0.2.7', '2001:db8::/32']);
    $this->patchJson("/api/admin/staff/{$target->id}", ['ip_allowlist' => []])->assertOk()->assertJsonPath('data.ip_allowlist', []);
});

it('an_owner_cannot_lock_themselves_out_with_the_allowlist', function (): void {
    $owner = $this->asAdmin(AdminRole::Owner);

    $this->patchJson("/api/admin/staff/{$owner->id}", ['ip_allowlist' => ['203.0.113.0/24']])
        ->assertStatus(422)->assertJsonPath('code', 'admin_ip_self_lockout');
    expect(AdminUser::query()->find($owner->id)->ip_allowlist)->toBe([]);

    // Their own address (the test client is 127.0.0.1) is fine, alone or inside a block.
    $this->patchJson("/api/admin/staff/{$owner->id}", ['ip_allowlist' => ['127.0.0.0/8']])->assertOk();
});

it('reset_two_factor_clears_the_secret_and_signs_the_member_out', function (): void {
    [$target, $secret] = $this->makeAdmin(AdminRole::Support);
    $targetOtp = fn (): string => (new Google2FA)->getCurrentOtp($secret);
    $this->signInAdmin($target, $secret);
    $this->getJson('/api/admin/session')->assertOk();

    [$owner] = $this->makeAdmin(AdminRole::Owner);
    app(ResetStaffTwoFactor::class)->handle(new AdminActor($owner->id, AdminRole::Owner), $target->id);

    $fresh = AdminUser::query()->find($target->id);
    expect($fresh->two_factor_secret)->toBeNull()->and($fresh->two_factor_recovery_codes)->toBeNull()->and($fresh->two_factor_confirmed_at)->toBeNull();
    app('auth')->forgetGuards();
    $this->getJson('/api/admin/session')->assertStatus(401);
    expect($targetOtp())->not->toBeEmpty()->and(staffAuditActions())->toContain('staff.two_factor_reset');
});

it('reset_two_factor_works_through_the_api', function (): void {
    [$target] = $this->makeAdmin(AdminRole::Support);
    $this->asAdmin(AdminRole::Owner);

    $this->postJson("/api/admin/staff/{$target->id}/reset-two-factor")->assertOk()->assertJsonPath('data.two_factor_enabled', false);
    expect(AdminUser::query()->find($target->id)->two_factor_confirmed_at)->toBeNull()
        ->and(staffAuditActions())->toContain('staff.two_factor_reset');
    $this->postJson('/api/admin/staff/'.Str::uuid7().'/reset-two-factor')->assertStatus(404);
});

it('deactivate_signs_the_member_out_and_blocks_sign_in', function (): void {
    [$target, $secret] = $this->makeAdmin(AdminRole::Support);
    $this->signInAdmin($target, $secret);
    $this->getJson('/api/admin/session')->assertOk();

    [$owner] = $this->makeAdmin(AdminRole::Owner);
    app(DeactivateStaff::class)->handle(new AdminActor($owner->id, AdminRole::Owner), $target->id);
    app('auth')->forgetGuards();
    $this->getJson('/api/admin/session')->assertStatus(401);

    $this->postJson('/api/admin/auth/login', ['email' => $target->email, 'password' => 'Staff-Password-42'])->assertStatus(422);
    expect(staffAuditActions())->toContain('staff.deactivated');
});

it('audit_list_filters', function (): void {
    [$target] = $this->makeAdmin(AdminRole::Support);
    $owner = $this->asAdmin(AdminRole::Owner);
    $tenant = (string) Str::uuid7();
    $this->patchJson("/api/admin/staff/{$target->id}", ['name' => 'One'])->assertOk();
    $this->postJson("/api/admin/staff/{$target->id}/deactivate")->assertOk();
    DB::connection('pgsql_platform')->transaction(fn () => app(AdminAuditWriter::class)->record(
        new AdminActor($target->id, AdminRole::Support), 'tenant.note_added', $tenant, 'tenant', $tenant));

    $today = $this->clock->now()->format('Y-m-d');
    $all = $this->getJson('/api/admin/audit')->assertOk()->json('data');
    expect(count($all))->toBeGreaterThanOrEqual(4)
        ->and($all[0])->toHaveKeys(['id', 'admin_id', 'action', 'tenant_id', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'ip', 'occurred_at'])
        ->and($all[0]['id'])->toBeGreaterThan($all[1]['id']);

    expect($this->getJson('/api/admin/audit?action=staff.updated')->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/admin/audit?tenant={$tenant}")->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/admin/audit?admin={$target->id}")->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/admin/audit?admin={$owner->id}&action=staff.deactivated")->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/admin/audit?from='.$this->clock->now()->modify('+1 day')->format('Y-m-d'))->json('data'))->toBe([])
        ->and($this->getJson('/api/admin/audit?to='.$this->clock->now()->modify('-1 day')->format('Y-m-d'))->json('data'))->toBe([])
        ->and(count($this->getJson('/api/admin/audit?from='.$today.'&to='.$today)->json('data')))->toBeGreaterThanOrEqual(4);
    $this->getJson('/api/admin/audit?admin=nope')->assertStatus(422);
    $this->getJson('/api/admin/audit?from=yesterday')->assertStatus(422);
});

it('audit_list_pages_by_cursor', function (): void {
    $this->asAdmin(AdminRole::Owner);
    [$target] = $this->makeAdmin(AdminRole::Support);
    foreach (['A', 'B', 'C'] as $n) {
        $this->patchJson("/api/admin/staff/{$target->id}", ['name' => $n])->assertOk();
    }

    $first = $this->getJson('/api/admin/audit?limit=2')->assertOk();
    expect($first->json('data'))->toHaveCount(2)->and($first->json('meta.next_cursor'))->not->toBeNull();
    $second = $this->getJson('/api/admin/audit?limit=2&cursor='.$first->json('meta.next_cursor'))->assertOk();
    $ids = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'));
    expect($ids)->toBe(array_values(array_unique($ids)))->and($second->json('data.0.id'))->toBeLessThan($first->json('data.1.id'));
    $this->getJson('/api/admin/audit?cursor=garbage')->assertStatus(422);
});

it('audit_view_needs_audit_view', function (): void {
    $this->asAdmin(AdminRole::Finance);
    $this->getJson('/api/admin/audit')->assertStatus(403);
    $this->postJson('/api/admin/audit/verify')->assertStatus(403);
});

it('verify_reports_intact', function (): void {
    $this->asAdmin(AdminRole::Readonly);
    $this->getJson('/api/admin/audit')->assertOk();
    $r = $this->postJson('/api/admin/audit/verify')->assertOk();

    expect($r->json('data.intact'))->toBeTrue()->and($r->json('data.events'))->toBeGreaterThanOrEqual(1)->and($r->json('data.broken_id'))->toBeNull();
});

it('an_older_session_stays_dead_after_a_two_factor_reset_and_a_new_sign_in', function (): void {
    [$target, $secret] = $this->makeAdmin(AdminRole::Support);
    $this->signInAdmin($target, $secret);
    $this->getJson('/api/admin/session')->assertOk();
    $olderSession = adminSessionData();

    [$owner] = $this->makeAdmin(AdminRole::Owner);
    app(ResetStaffTwoFactor::class)->handle(new AdminActor($owner->id, AdminRole::Owner), $target->id);

    // The person re-enrols and signs in anew (a second session).
    $newSecret = (new Google2FA)->generateSecretKey();
    AdminUser::query()->whereKey($target->id)->first()->forceFill(['two_factor_secret' => $newSecret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []])->save();
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->signInAdmin(AdminUser::query()->find($target->id), $newSecret);
    $this->getJson('/api/admin/session')->assertOk();

    // The first session is still dead.
    app('auth')->forgetGuards();
    restoreAdminSession($olderSession);
    $this->getJson('/api/admin/session')->assertStatus(401);
});

it('a_role_change_ends_existing_sessions_but_other_sessions_are_unaffected', function (): void {
    [$target, $secret] = $this->makeAdmin(AdminRole::Support);
    [$bystander, $bystanderSecret] = $this->makeAdmin(AdminRole::Finance);
    $this->signInAdmin($bystander, $bystanderSecret);
    $bystanderSession = adminSessionData();
    $this->signInAdmin($target, $secret);
    $targetSession = adminSessionData();
    $this->getJson('/api/admin/session')->assertOk();

    [$owner] = $this->makeAdmin(AdminRole::Owner);
    $actor = new AdminActor($owner->id, AdminRole::Owner);
    app(UpdateStaff::class)->handle($actor, $target->id, ['name' => 'Only a rename']);
    app('auth')->forgetGuards();
    restoreAdminSession($targetSession);
    $this->getJson('/api/admin/session')->assertOk();

    app(UpdateStaff::class)->handle($actor, $target->id, ['role' => AdminRole::Engineer]);
    app('auth')->forgetGuards();
    restoreAdminSession($targetSession);
    $this->getJson('/api/admin/session')->assertStatus(401);

    app('auth')->forgetGuards();
    restoreAdminSession($bystanderSession);
    $this->getJson('/api/admin/session')->assertOk();
});

it('deactivation_and_password_set_bump_the_auth_epoch', function (): void {
    $this->asAdmin(AdminRole::Owner);
    [$id, $token] = inviteStaff($this);
    $epoch = fn (): int => (int) DB::connection('pgsql_platform')->table('admin_users')->where('id', $id)->value('auth_epoch');
    $start = $epoch();

    $this->postJson('/api/admin/auth/logout');
    $this->postJson('/api/admin/auth/set-password', ['token' => $token, 'password' => 'Brand-New-Pass-42'])->assertOk();
    expect($epoch())->toBe($start + 1);

    [$owner, $secret] = $this->makeAdmin(AdminRole::Owner);
    $this->signInAdmin($owner, $secret);
    $this->postJson("/api/admin/staff/{$id}/deactivate")->assertOk();
    expect($epoch())->toBe($start + 2);
});
