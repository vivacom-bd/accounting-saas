<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Domain\Shared\DomainRuleViolation;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/** Drops a failed job for good (`queue:forget`), through the queue failer on the app connection. Audited. */
final class ForgetFailedJob
{
    public function __construct(
        private readonly FailedJobProviderInterface $failer,
        private readonly AdminAuditWriter $audit,
    ) {}

    public function handle(AdminActor $actor, string $uuid): void
    {
        $failed = (array) ($this->failer->find($uuid) ?? throw DomainRuleViolation::single('not_found', 'Failed job not found.'));
        $payload = json_decode((string) $failed['payload'], true);
        $job = is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : null;

        $this->failer->forget($uuid);

        AdminTransaction::run(fn () => $this->audit->record($actor, 'failed_job.forgotten', null, 'failed_job', $uuid, ['job' => $job, 'queue' => (string) $failed['queue']]));
    }
}
