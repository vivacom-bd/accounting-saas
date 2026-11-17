<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin support tools (plan Task 15). `mail_log` and `announcements` are platform tables (no RLS; Rls::GLOBAL_TABLES).
 *
 * mail_log: one row per sent email, no body, recipient as sha256 + masked form. Written only through
 * sysops.record_mail_sent (the sysops role holds the INSERT; the app and platform roles call the function); the
 * platform role may only read it. No FK on tenant_id: the log outlives a tenant.
 *
 * announcements: written by staff (platform role, full DML); tenants read them only through
 * sysops.active_announcements(), bound to app.tenant_id.
 *
 * Failed-job retry and forget run on the app connection through the queue failer (which already uses it), so the
 * platform role keeps SELECT only on failed_jobs: no new grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->char('to_hash', 64);
            $table->string('to_masked', 320);
            $table->string('subject', 255);
            $table->string('notification', 255)->nullable();
            $table->string('status', 16)->default('sent');
            $table->timestampTz('sent_at', 6);
            $table->index(['sent_at', 'id']);
            $table->index(['tenant_id', 'sent_at']);
        });
        DB::statement("ALTER TABLE mail_log ADD CONSTRAINT mail_log_status_check CHECK (status IN ('sent'))");

        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 160);
            $table->text('body');
            $table->string('kind', 16);
            $table->jsonb('audience')->default('{}');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->foreign('created_by')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['starts_at', 'id']);
        });
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_kind_check CHECK (kind IN ('info', 'warning', 'maintenance'))");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_audience_check CHECK (jsonb_typeof(audience) = 'object')");
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_window_check CHECK (ends_at IS NULL OR ends_at > starts_at)');

        $db = DB::connection();
        $db->statement('REVOKE ALL ON mail_log, announcements FROM PUBLIC, ledgerly_app, ledgerly_report');
        $db->statement('GRANT SELECT ON mail_log TO ledgerly_platform');
        $db->statement('GRANT SELECT, INSERT, UPDATE, DELETE ON announcements TO ledgerly_platform');
        $db->statement('GRANT INSERT ON mail_log TO ledgerly_sysops');
        $db->statement('GRANT SELECT ON announcements TO ledgerly_sysops');

        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_support.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.active_announcements()');
        DB::statement('DROP FUNCTION IF EXISTS sysops.record_mail_sent(text, text, text, text)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_webhook_deliveries(uuid, integer)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_search_companies(text, integer)');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('mail_log');
    }
};
