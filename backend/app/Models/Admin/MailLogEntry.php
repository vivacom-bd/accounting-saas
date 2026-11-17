<?php

declare(strict_types=1);

namespace App\Models\Admin;

use App\Models\BaseModel;
use DateTimeImmutable;

/**
 * One sent email in the platform mail log (plan Task 15). Read-only for staff; rows arrive through
 * sysops.record_mail_sent. Holds a recipient hash and masked address, never a body.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $to_masked
 * @property string $subject
 * @property string|null $notification
 * @property string $status
 * @property DateTimeImmutable $sent_at
 */
final class MailLogEntry extends BaseModel
{
    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'mail_log';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
