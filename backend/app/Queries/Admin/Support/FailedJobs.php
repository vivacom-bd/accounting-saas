<?php

declare(strict_types=1);

namespace App\Queries\Admin\Support;

use App\Http\Support\CursorPage;
use App\Models\Admin\FailedJob;
use Illuminate\Http\Request;

/**
 * `GET /api/admin/jobs/failed`: newest first. Never the payload (it carries the serialised command) nor the stack
 * trace: the job class from the payload's displayName, queue, connection, failed_at and the first line of the
 * exception, cut to 300 characters.
 */
final class FailedJobs
{
    public function __invoke(Request $request): CursorPage
    {
        $query = FailedJob::query()->selectRaw(
            "id, uuid, connection AS connection_name, queue, failed_at, (payload::jsonb)->>'displayName' AS job, left(split_part(exception, E'\\n', 1), 300) AS exception_line"
        );

        return CursorPage::fetch($query, $request, 'id', true, 'int');
    }
}
