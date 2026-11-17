<?php

declare(strict_types=1);

namespace App\Queries\Admin\Compliance;

use App\Contracts\Clock;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;

/** Tenants with a live (scheduled or blocked) deletion, soonest first, for the retention view (plan Task 18, D8). */
final class RetentionSchedule
{
    public function __construct(private readonly Clock $clock) {}

    /** @return list<array{tenant_id: string, name: string, lifecycle: string, status: string, scheduled_for: string, days_left: int, legal_hold: bool, archive_until: string|null}> */
    public function __invoke(): array
    {
        $today = new DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        $rows = AdminTransaction::connection()->table('tenant_deletions as d')
            ->join('tenants as t', 't.id', '=', 'd.tenant_id')
            ->whereIn('d.status', ['scheduled', 'blocked'])
            ->orderBy('d.scheduled_for')->orderBy('t.id')
            ->get(['t.id', 't.name', 't.lifecycle', 't.legal_hold', 't.archive_until', 'd.status', 'd.scheduled_for']);

        $out = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $out[] = [
                'tenant_id' => (string) $r['id'],
                'name' => (string) $r['name'],
                'lifecycle' => (string) $r['lifecycle'],
                'status' => (string) $r['status'],
                'scheduled_for' => (string) $r['scheduled_for'],
                'days_left' => (int) $today->diff(new DateTimeImmutable((string) $r['scheduled_for']))->format('%r%a'),
                'legal_hold' => (bool) $r['legal_hold'],
                'archive_until' => is_string($r['archive_until']) ? $r['archive_until'] : null,
            ];
        }

        return $out;
    }
}
