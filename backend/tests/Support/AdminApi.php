<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Admin\AdminRole;
use App\Models\Admin\AdminUser;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Staff and sign-in helpers for the admin surface: real password + TOTP sign-in through the API. */
trait AdminApi
{
    public const string ADMIN_API_PASSWORD = 'Staff-Password-42';

    private int $adminSeq = 0;

    /** @return array{AdminUser, string} the staff member (TOTP confirmed) and their TOTP secret */
    public function makeAdmin(AdminRole $role): array
    {
        $secret = (new Google2FA)->generateSecretKey();
        $n = ++$this->adminSeq;
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid7(), 'email' => "{$role->value}{$n}-".Str::lower(Str::random(6)).'@ledgerly.test',
            'name' => ucfirst($role->value)." {$n}", 'password' => self::ADMIN_API_PASSWORD, 'role' => $role->value,
            'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => [],
        ]);

        return [$admin, $secret];
    }

    public function signInAdmin(AdminUser $admin, string $secret): void
    {
        $this->postJson('/api/admin/auth/logout');
        $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => self::ADMIN_API_PASSWORD])->assertOk();
        $this->postJson('/api/admin/auth/two-factor', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }

    /** A new staff member with the role, signed in on the test client. */
    public function asAdmin(AdminRole $role): AdminUser
    {
        [$admin, $secret] = $this->makeAdmin($role);
        $this->signInAdmin($admin, $secret);
/** Signs a platform staff member in through the real password + TOTP flow (plan Task 3). */
trait AdminApi
{
    /** The test client is left signed in as a new admin with the given role. */
    protected function asAdmin(AdminRole $role = AdminRole::Owner): AdminUser
    {
        $secret = (new Google2FA)->generateSecretKey();
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid7(), 'email' => $role->value.'-'.Str::lower(Str::random(6)).'@ledgerly.test',
            'name' => ucfirst($role->value).' Staff', 'password' => 'Staff-Password-42', 'role' => $role,
            'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => [],
        ]);

        $this->withHeaders(['Accept' => 'application/json']);
        $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => 'Staff-Password-42'])->assertOk();
        $this->postJson('/api/admin/auth/two-factor', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();

        return $admin;
    }
}
