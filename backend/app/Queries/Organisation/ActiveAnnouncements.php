<?php

declare(strict_types=1);

namespace App\Queries\Organisation;

use Illuminate\Support\Facades\DB;

/**
 * The announcements the current organisation should see (plan Task 15). The app role has no grant on the platform
 * table; sysops.active_announcements() reads it, bound to the session's app.tenant_id, and applies the audience
 * (plan, lifecycle, country) and the start/end window.
 */
final class ActiveAnnouncements
{
    /** @return list<array{id: string, title: string, body: string, kind: string, starts_at: string, ends_at: string|null}> */
    public function __invoke(): array
    {
        $rows = DB::select('SELECT id, title, body, kind, starts_at, ends_at FROM sysops.active_announcements()');

        return array_values(array_map(function (object $row): array {
            $r = (array) $row;

            return [
                'id' => (string) $r['id'], 'title' => (string) $r['title'], 'body' => (string) $r['body'], 'kind' => (string) $r['kind'],
                'starts_at' => (new \DateTimeImmutable((string) $r['starts_at']))->format(DATE_ATOM),
                'ends_at' => $r['ends_at'] === null ? null : (new \DateTimeImmutable((string) $r['ends_at']))->format(DATE_ATOM),
            ];
        }, $rows));
    }
}
