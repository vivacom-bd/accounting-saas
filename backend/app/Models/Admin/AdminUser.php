<?php

declare(strict_types=1);

namespace App\Models\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\BaseModel;
use App\Models\Casts\PgInetArray;
use DateTimeImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform staff (10_SaaS_Admin §6). Authenticates on the `admin` guard only; lives on the platform connection,
 * which the tenant app role cannot read. The TOTP secret is encrypted; recovery codes are kept as an encrypted list
 * of SHA-256 hashes, so neither the database nor its backups hold a usable code.
 *
 * @property string $id
 * @property string $email
 * @property string $name
 * @property string $password
 * @property AdminRole $role
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property DateTimeImmutable|null $two_factor_confirmed_at
 * @property list<string> $ip_allowlist
 * @property bool $is_active
 * @property int $auth_epoch bumped to end every older session of this staff member
 * @property string|null $password_set_token_hash
 * @property DateTimeImmutable|null $password_set_expires_at
 * @property DateTimeImmutable|null $last_login_at
 * @property DateTimeImmutable|null $created_at
 * @property DateTimeImmutable|null $updated_at
 */
class AdminUser extends BaseModel implements AuthenticatableContract
{
    use Authenticatable;

    protected $connection = 'pgsql_platform';

    protected $table = 'admin_users';

    /** @var list<string> */
    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'password_set_token_hash'];

    /** No "remember me" for staff: there is no remember_token column. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * Case-insensitive, matching the unique index on lower(email).
     *
     * @param  Builder<AdminUser>  $query
     * @return Builder<AdminUser>
     */
    public function scopeEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => AdminRole::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'ip_allowlist' => PgInetArray::class,
            'is_active' => 'boolean',
            'auth_epoch' => 'integer',
            'password_set_expires_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
