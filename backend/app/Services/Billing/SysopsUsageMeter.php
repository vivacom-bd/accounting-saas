<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\UsageMeter;
use Illuminate\Database\DatabaseManager;

/** The usage counters live in a platform table the app role cannot touch; this goes through sysops.bump_usage / usage_summary. */
final class SysopsUsageMeter implements UsageMeter
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function bump(string $metric, int $n = 1, ?string $dedupKey = null): void
    {
        $this->db->connection()->select('SELECT sysops.bump_usage(?, ?, ?)', [$metric, $n, $dedupKey]);
    }

    public function summary(): array
    {
        $out = [];
        foreach ($this->db->connection()->select('SELECT * FROM sysops.usage_summary()') as $row) {
            $out[(string) $row->metric] = ['today' => (int) $row->today, 'month' => (int) $row->month, 'total' => (int) $row->total];
        }

        return $out;
    }
}
