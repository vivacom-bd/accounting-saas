<?php

declare(strict_types=1);

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of the queue's failed_jobs table as admin support sees it (plan Task 15): read through the platform role
 * (SELECT only) and only the safe columns; retry and forget go through the queue failer on the app connection.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection_name
 * @property string $queue
 * @property string|null $job
 * @property string $exception_line
 * @property mixed $failed_at
 */
final class FailedJob extends Model
{
    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'failed_jobs';

    protected $guarded = [];
}
