<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use DateTimeImmutable;

/**
 * An impersonation as the tenant app sees it: read from the database on every request, never from the session alone.
 * `live` = started, not ended, before its end, the member still active and support access still open (or overridden).
 */
final readonly class Impersonation
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public string $adminId,
        public string $adminName,
        public string $mode,
        public DateTimeImmutable $endsAt,
        public bool $live = true,
    ) {}

    public function readOnly(): bool
    {
        return $this->mode !== 'write';
    }

    /** @return array<string, string> added to every tenant audit event written during the impersonation (T6) */
    public function auditContext(): array
    {
        return ['admin_id' => $this->adminId, 'impersonation_id' => $this->id];
    }

    /** @return array{admin_name: string, mode: string, ends_at: string} what the SPA banner shows */
    public function banner(): array
    {
        return ['admin_name' => $this->adminName, 'mode' => $this->mode, 'ends_at' => $this->endsAt->format(DATE_RFC3339)];
    }
}
