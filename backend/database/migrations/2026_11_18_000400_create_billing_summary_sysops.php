<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-side billing read (plan Task 24, 4b.8): `sysops.tenant_billing_summary()` exposes the organisation's own live
 * subscription and recent invoices to the app role, bound to app.tenant_id. Read-only, SECURITY DEFINER owned by
 * ledgerly_sysops (BYPASSRLS). No new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();
        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_billing.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.tenant_billing_summary()');
    }
};
