<?php

declare(strict_types=1);

namespace App\Models\Admin;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * A staff member's impersonation of one tenant member (plan Task 14, G4/T6). Platform table on `pgsql_platform`; the
 * tenant app reaches it only through sysops functions. `ticket_hash` is the SHA-256 of the single-use ticket.
 *
 * @property string $id
 * @property string $admin_id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $mode read|write
 * @property string $reason
 * @property string|null $grant_id
 * @property bool $owner_override
 * @property string|null $approval_id
 * @property string $ticket_hash
 * @property DateTimeImmutable $ticket_expires_at
 * @property DateTimeImmutable|null $consumed_at
 * @property DateTimeImmutable|null $started_at
 * @property DateTimeImmutable $ends_at
 * @property DateTimeImmutable|null $ended_at
 * @property DateTimeImmutable $created_at
 */
class ImpersonationSession extends BaseModel
{
    public const UPDATED_AT = null;

    protected $connection = 'pgsql_platform';

    protected $table = 'impersonation_sessions';

    protected $hidden = ['ticket_hash'];

    /** pending (ticket not used yet) | active | ended | expired (ticket or session ran out without an explicit end) */
    public function status(DateTimeImmutable $now): string
    {
        return match (true) {
            $this->ended_at !== null => 'ended',
            $this->ends_at <= $now => 'expired',
            $this->consumed_at !== null => 'active',
            $this->ticket_expires_at <= $now => 'expired',
            default => 'pending',
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'owner_override' => 'boolean',
            'ticket_expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
