<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Growth funnel support (plan Task 16): `sysops.admin_activation_flags(uuid[])` reports, per organisation, whether it
 * has an active company, has posted a journal and how many active members it has. Read-only, SECURITY DEFINER owned by
 * ledgerly_sysops (BYPASSRLS), EXECUTE to ledgerly_platform only. No new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();
        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_growth.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_activation_flags(uuid[])');
    }
};
