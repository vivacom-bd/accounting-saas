<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consented, time-boxed impersonation (plan Task 14, decisions G4 and T6; 13 §18).
 *
 * - `support_access_grants` is a tenant table (RLS): an organisation owner or admin opens support access for 24, 72 or
 *   168 hours and may revoke it. The app role may read, add and revoke (UPDATE), never delete.
 * - `impersonation_sessions` is a platform table (no RLS; Rls::GLOBAL_TABLES): what staff started, in which mode, with
 *   which grant, override or approval. `ledgerly_platform` adds rows and ends them; the app role has no access at all
 *   and reaches its own sessions only through the sysops functions in sysops_impersonation.sql (ticket exchange, the
 *   per-request liveness check, ending). Only the SHA-256 of a ticket is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('granted_by');
            $table->smallInteger('hours');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('created_at', 6);
            $table->foreign('granted_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'expires_at']);
        });
        DB::statement('ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grants_hours CHECK (hours IN (24, 72, 168))');
        DB::statement('ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grants_revoked CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))');
        Rls::enable(DB::connection(), 'support_access_grants');

        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('admin_id');
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('mode', 8);
            $table->text('reason');
            $table->uuid('grant_id')->nullable();
            $table->boolean('owner_override')->default(false);
            $table->uuid('approval_id')->nullable();
            $table->char('ticket_hash', 64)->unique();
            $table->timestampTz('ticket_expires_at', 6);
            $table->timestampTz('consumed_at', 6)->nullable();
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('ends_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->foreign('admin_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approval_id')->references('id')->on('admin_approvals')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_mode CHECK (mode IN ('read', 'write'))");
        // Write mode always spends an approval; inside a grant or by owner override, never both and never neither.
        DB::statement("ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_write_approval CHECK ((mode = 'write') = (approval_id IS NOT NULL))");
        DB::statement('ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_basis CHECK (owner_override <> (grant_id IS NOT NULL))');
        DB::statement("ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_ticket_hash CHECK (ticket_hash ~ '^[0-9a-f]{64}$')");

        $db = DB::connection();
        $db->statement('REVOKE DELETE ON support_access_grants FROM ledgerly_app');
        $db->statement('REVOKE ALL ON impersonation_sessions FROM PUBLIC, ledgerly_app, ledgerly_report');
        $db->statement('GRANT SELECT, INSERT ON impersonation_sessions TO ledgerly_platform');
        $db->statement('GRANT UPDATE (ended_at) ON impersonation_sessions TO ledgerly_platform');
        $db->statement('GRANT UPDATE (consumed_at, started_at, ended_at) ON impersonation_sessions TO ledgerly_sysops');

        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_impersonation.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.end_tenant_impersonations(timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.end_impersonation(uuid, uuid, timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.impersonation_state(uuid, uuid, timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.impersonation_by_ticket(text, timestamptz)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_support_access_open(uuid, timestamptz)');
        DB::statement('REVOKE UPDATE (consumed_at, started_at, ended_at) ON impersonation_sessions FROM ledgerly_sysops');
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('support_access_grants');
    }
};
