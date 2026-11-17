<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Puts a failed job back on its queue (`queue:retry` semantics, through the queue failer rather than Artisan): push
 * the stored payload with its attempts reset, then forget the failed row. Runs on the app connection (the failer's and
 * the queue's own), so the platform role needs no write grant on failed_jobs or jobs. The admin audit event is written
 * after the requeue (T4 order); the payload itself is never audited.
 */
final class RetryFailedJob
{
    public function __construct(
        private readonly FailedJobProviderInterface $failer,
        private readonly QueueFactory $queues,
        private readonly AdminAuditWriter $audit,
    ) {}

    /** @return array{id: string, job: string|null, queue: string} */
    public function handle(AdminActor $actor, string $uuid): array
    {
        $found = $this->failer->find($uuid) ?? throw DomainRuleViolation::single('not_found', 'Failed job not found.');
        $failed = (array) $found;
        $payload = json_decode((string) $failed['payload'], true);
        if (! is_array($payload)) {
            throw DomainRuleViolation::single('failed_job_unreadable', 'The failed job has no readable payload; forget it instead.');
        }
        $payload['attempts'] = 0;
        unset($payload['retryUntil']);

        $this->queues->connection((string) $failed['connection'])->pushRaw(json_encode($payload, JSON_THROW_ON_ERROR), (string) $failed['queue']);
        $this->failer->forget($uuid);

        $job = is_string($payload['displayName'] ?? null) ? $payload['displayName'] : null;
        AdminTransaction::run(fn () => $this->audit->record($actor, 'failed_job.retried', null, 'failed_job', $uuid, null, ['job' => $job, 'queue' => (string) $failed['queue']]));

        return ['id' => $uuid, 'job' => $job, 'queue' => (string) $failed['queue']];
    }
}
