<?php

declare(strict_types=1);

namespace App\Support\Outbox;

use App\Contracts\OutboxHandler;
use App\Contracts\UsageMeter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * A journal was posted (plan Task 10): count its lines against the tenant's monthly journal-line usage. This runs from the
 * outbox AFTER the posting committed, so metering never sits inside the posting transaction and never blocks it. Delivery is
 * at least once, so the bump is keyed by the message id: a redelivery counts nothing the second time.
 */
final class UsageOutboxHandler implements OutboxHandler
{
    /** @var list<string> */
    public const array TYPES = ['journal.posted'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $tenant,
        private readonly UsageMeter $usage,
    ) {}

    public function handle(OutboxMessage $message): void
    {
        if ($this->tenant->require() !== $message->tenantId) {
            throw new LogicException('The outbox message does not belong to the current tenant.');
        }
        $lines = $this->db->connection()->table('journal_lines')->where('journal_id', (string) ($message->payload['journal_id'] ?? ''))->count();
        if ($lines > 0) {
            $this->usage->bump('journal_lines', $lines, 'outbox:'.$message->id);
        }
    }
}
