<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform admin tables (10_SaaS_Admin §6, plan 4b.1). Platform tables: no tenant_id, no RLS (allowlisted in
 * Rls::GLOBAL_TABLES). Only the `ledgerly_platform` role may touch them; the tenant app and report roles get nothing.
 * The platform role also reads the tenant directory and may update the lifecycle columns of `tenants`, and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->char('country', 2)->nullable();
            $table->timestampTz('lifecycle_since')->nullable();
            $table->timestampTz('delinquent_since')->nullable();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email', 255);
            $table->string('name', 255);
            $table->string('password');
            $table->string('role', 16);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE admin_users ADD COLUMN ip_allowlist inet[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE admin_users ADD CONSTRAINT admin_users_role_check CHECK (role IN ('owner','finance','sales','support','engineer','readonly'))");
        DB::statement('CREATE UNIQUE INDEX admin_users_email_unique ON admin_users (lower(email))');

        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('admin_id')->nullable();
            $table->string('action', 64);
            $table->uuid('tenant_id')->nullable();
            $table->string('subject_type', 64);
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->text('canonical');
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->index('occurred_at');
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('admin_audit_head', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->bigInteger('last_id')->nullable();
            $table->char('last_hash', 64)->nullable();
        });
        DB::statement('ALTER TABLE admin_audit_head ADD CONSTRAINT admin_audit_head_single CHECK (id = 1)');
        DB::table('admin_audit_head')->insert(['id' => 1]);

        Schema::create('admin_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 32);
            $table->uuid('tenant_id')->nullable();
            $table->jsonb('subject')->default('{}');
            $table->text('reason');
            $table->uuid('requested_by');
            $table->uuid('approved_by')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('requested_by')->references('id')->on('admin_users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('admin_users')->restrictOnDelete();
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE admin_approvals ADD CONSTRAINT admin_approvals_kind_check CHECK (kind IN ('refund','impersonate_write','plan_migration','tenant_delete','legal_hold_release'))");
        DB::statement("ALTER TABLE admin_approvals ADD CONSTRAINT admin_approvals_status_check CHECK (status IN ('pending','approved','rejected','executed','expired'))");

        // Default privileges hand the app and report roles DML/SELECT on every new table; admin tables are not theirs.
        $db = DB::connection();
        $admin = 'admin_users, admin_audit_events, admin_audit_head, admin_approvals';
        $db->statement("REVOKE ALL ON {$admin}, admin_audit_events_id_seq FROM PUBLIC, ledgerly_app, ledgerly_report");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$admin} TO ledgerly_platform");
        $db->statement('GRANT USAGE, SELECT ON admin_audit_events_id_seq TO ledgerly_platform');
        $db->statement('GRANT SELECT ON tenants, tenant_users, failed_jobs TO ledgerly_platform');
        // Never secrets: no password, remember token, 2FA secret or recovery codes, signing PIN hash.
        $db->statement('GRANT SELECT (id, name, email, email_verified_at, two_factor_confirmed_at, signing_pin_set_at, signing_locked_until, locale, last_login_at, created_at, updated_at) ON users TO ledgerly_platform');
        $db->statement('GRANT UPDATE (lifecycle, lifecycle_since, delinquent_since, trial_ends_at, legal_hold, archive_until, country) ON tenants TO ledgerly_platform');

        SqlFile::run($db, 'admin_audit_guard.sql');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS admin_audit_events_immutable ON admin_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS admin_audit_append_only()');
        Schema::dropIfExists('admin_approvals');
        Schema::dropIfExists('admin_audit_head');
        Schema::dropIfExists('admin_audit_events');
        Schema::dropIfExists('admin_users');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['country', 'lifecycle_since', 'delinquent_since']);
        });
    }
};
