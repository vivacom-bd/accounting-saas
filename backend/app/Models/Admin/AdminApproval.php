<?php

declare(strict_types=1);

namespace App\Models\Admin;

use App\Domain\Admin\ApprovalKind;
use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * A request that needs a second staff member (plan Task 4). Stored status is pending|approved|rejected|executed;
 * a pending or approved row past `expires_at` counts as expired (see effectiveStatus()).
 *
 * @property string $id
 * @property ApprovalKind $kind
 * @property string|null $tenant_id
 * @property array<string, mixed> $subject
 * @property string $reason
 * @property string $requested_by
 * @property string|null $approved_by who decided it (approved or rejected)
 * @property string $status
 * @property string|null $rejection_reason
 * @property DateTimeImmutable $expires_at
 * @property DateTimeImmutable|null $decided_at
 * @property DateTimeImmutable|null $executed_at
 * @property DateTimeImmutable|null $created_at
 */
class AdminApproval extends BaseModel
{
    public const UPDATED_AT = null;

    protected $connection = 'pgsql_platform';

    protected $table = 'admin_approvals';

    public function effectiveStatus(DateTimeImmutable $now): string
    {
        return in_array($this->status, ['pending', 'approved'], true) && $this->expires_at <= $now ? 'expired' : $this->status;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ApprovalKind::class,
            'subject' => 'array',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
