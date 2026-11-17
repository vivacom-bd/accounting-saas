<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin tenant page (plan Task 7): staff notes on a tenant, and the sysops.admin_* functions through which the
 * platform role reads tenant figures and makes its two narrow member writes (2FA reset, disable). `tenant_notes` is a
 * platform table (no RLS; Rls::GLOBAL_TABLES): ledgerly_platform may read, add and pin, never delete; the tenant app
 * and report roles get nothing. The platform role gets USAGE on the sysops schema and EXECUTE on the admin functions
 * only; sysops gets just the user/membership columns those two writes change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('admin_id');
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestampTz('created_at', 6);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('admin_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        $db = DB::connection();
        $db->statement('REVOKE ALL ON tenant_notes FROM PUBLIC, ledgerly_app, ledgerly_report');
        $db->statement('GRANT SELECT, INSERT ON tenant_notes TO ledgerly_platform');
        $db->statement('GRANT UPDATE (pinned) ON tenant_notes TO ledgerly_platform');

        $db->statement('GRANT USAGE ON SCHEMA sysops TO ledgerly_platform');
        $db->statement('GRANT UPDATE (two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at, updated_at) ON users TO ledgerly_sysops');
        $db->statement('GRANT UPDATE (status, updated_at) ON tenant_users TO ledgerly_sysops');

        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_admin.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_set_member_status(uuid, uuid, text)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_reset_two_factor(uuid, uuid)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_tenant_users(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_tenant_companies(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_tenant_counts(uuid[])');
        DB::statement('REVOKE UPDATE (status, updated_at) ON tenant_users FROM ledgerly_sysops');
        DB::statement('REVOKE UPDATE (two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at, updated_at) ON users FROM ledgerly_sysops');
        DB::statement('REVOKE USAGE ON SCHEMA sysops FROM ledgerly_platform');
        Schema::dropIfExists('tenant_notes');
    }
};
