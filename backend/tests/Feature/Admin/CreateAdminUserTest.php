<?php

declare(strict_types=1);

use App\Models\Admin\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/* `admin:create` bootstraps platform staff from the server (plan Task 3); the password is never an argument. */

const BOOTSTRAP_PASSWORD = 'Bootstrap-Password-42';

it('creates a staff member with a hidden password and records it', function (): void {
    $this->artisan('admin:create', ['email' => 'Boss@Ledgerly.test', 'name' => 'The Boss', '--role' => 'owner'])
        ->expectsQuestion('Password', BOOTSTRAP_PASSWORD)
        ->expectsQuestion('Confirm password', BOOTSTRAP_PASSWORD)
        ->expectsOutputToContain('Created owner boss@ledgerly.test')
        ->assertExitCode(0);

    $admin = AdminUser::query()->sole();
    expect($admin->email)->toBe('boss@ledgerly.test')
        ->and($admin->role->value)->toBe('owner')
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->two_factor_confirmed_at)->toBeNull()
        ->and(Hash::check(BOOTSTRAP_PASSWORD, $admin->password))->toBeTrue();

    $event = DB::connection('pgsql_platform')->table('admin_audit_events')->sole();
    expect($event->action)->toBe('staff.bootstrapped')
        ->and($event->admin_id)->toBe($admin->id)
        ->and($event->subject_type)->toBe('admin_user')
        ->and($event->subject_id)->toBe($admin->id)
        ->and($event->after)->not->toContain('password');
});

it('refuses an email that already belongs to staff', function (): void {
    $this->artisan('admin:create', ['email' => 'boss@ledgerly.test', 'name' => 'A', '--role' => 'owner'])
        ->expectsQuestion('Password', BOOTSTRAP_PASSWORD)->expectsQuestion('Confirm password', BOOTSTRAP_PASSWORD)->assertExitCode(0);

    $this->artisan('admin:create', ['email' => 'BOSS@ledgerly.test', 'name' => 'B', '--role' => 'finance'])
        ->expectsOutputToContain('already exists')->assertExitCode(1);

    expect(AdminUser::query()->count())->toBe(1);
});

it('refuses an unknown role, a weak password and a mismatched confirmation', function (): void {
    $this->artisan('admin:create', ['email' => 'a@ledgerly.test', 'name' => 'A', '--role' => 'god'])->assertExitCode(1);
    $this->artisan('admin:create', ['email' => 'a@ledgerly.test', 'name' => 'A', '--role' => 'owner'])
        ->expectsQuestion('Password', 'short')->expectsQuestion('Confirm password', 'short')->assertExitCode(1);
    $this->artisan('admin:create', ['email' => 'a@ledgerly.test', 'name' => 'A', '--role' => 'owner'])
        ->expectsQuestion('Password', BOOTSTRAP_PASSWORD)->expectsQuestion('Confirm password', BOOTSTRAP_PASSWORD.'x')->assertExitCode(1);

    expect(AdminUser::query()->count())->toBe(0)
        ->and(DB::connection('pgsql_platform')->table('admin_audit_events')->count())->toBe(0);
});
