<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant lifecycle history and scheduled deletions (plan Task 5, decisions G5–G7; 10_SaaS_Admin §5–6, 05 §tenant_deletions).
 * Platform tables: no RLS (Rls::GLOBAL_TABLES). Only `ledgerly_platform` reads and writes them; lifecycle events are
 * append-only for that role (no UPDATE/DELETE grant). No purge this round: `deleted_at`/`final_audit_tip` stay empty until 4.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_lifecycle_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->text('reason');
            $table->string('actor_type', 32);
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('tenant_deletions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('scheduled_for');
            $table->string('status', 16)->default('scheduled');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->char('final_audit_tip', 64)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('admin_users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE tenant_deletions ADD CONSTRAINT tenant_deletions_status_check CHECK (status IN ('scheduled','blocked','withdrawn','executed'))");
        // One live (scheduled or blocked) deletion per tenant.
        DB::statement("CREATE UNIQUE INDEX tenant_deletions_live_unique ON tenant_deletions (tenant_id) WHERE status IN ('scheduled','blocked')");

        $db = DB::connection();
        $db->statement('REVOKE ALL ON tenant_lifecycle_events, tenant_deletions FROM PUBLIC, ledgerly_app, ledgerly_report');
        $db->statement('GRANT SELECT, INSERT ON tenant_lifecycle_events TO ledgerly_platform');
        $db->statement('GRANT SELECT, INSERT, UPDATE ON tenant_deletions TO ledgerly_platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_deletions');
        Schema::dropIfExists('tenant_lifecycle_events');
    }
};
