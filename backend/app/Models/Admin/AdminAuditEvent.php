<?php

declare(strict_types=1);

namespace App\Models\Admin;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * One link of the platform admin hash chain (plan T10). Read-only: written by AdminAuditWriter; the table refuses
 * updates and deletes. Lives on the platform connection.
 *
 * @property int $id
 * @property string|null $admin_id
 * @property string $action
 * @property string|null $tenant_id
 * @property string $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property DateTimeImmutable $occurred_at
 * @property string $canonical
 * @property string|null $prev_hash
 * @property string $hash
 */
class AdminAuditEvent extends BaseModel
{
    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'admin_audit_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
