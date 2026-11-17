<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** One platform transaction for an admin write and its audit event. Tenant-internal work commits before this (T4). */
final class AdminTransaction
{
    public const string CONNECTION = 'pgsql_platform';

    public static function connection(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(Closure $work): mixed
    {
        return self::connection()->transaction($work);
    }
}
