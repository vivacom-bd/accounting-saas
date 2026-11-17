<?php

declare(strict_types=1);

namespace App\Models\Organisation;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * Support access opened by an organisation owner or admin (plan Task 14, G4): staff may impersonate a member only while
 * one is open (not revoked, not expired), unless a staff Owner overrides with a reason. Tenant table, RLS.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $granted_by
 * @property int $hours 24, 72 or 168
 * @property DateTimeImmutable $expires_at
 * @property DateTimeImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property DateTimeImmutable $created_at
 */
class SupportAccessGrant extends BaseModel
{
    public const array HOURS = [24, 72, 168];

    public const UPDATED_AT = null;

    protected $table = 'support_access_grants';

    public function isOpen(DateTimeImmutable $now): bool
    {
        return $this->revoked_at === null && $this->expires_at > $now;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hours' => 'integer',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
