<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Session epoch for staff (plan Task 8 follow-up): a session records the epoch it signed in under, and every request
 * compares it with this column. Bumped on 2FA reset, deactivation, password set and role change, which ends every
 * older session of that person, including ones from before a re-enrolment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE admin_users ADD COLUMN auth_epoch integer NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE admin_users DROP COLUMN auth_epoch');
    }
};
