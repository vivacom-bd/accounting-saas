<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entitlement overrides and usage counters (plan Task 10, G8, T7). Platform tables (no RLS; allowlisted in
 * Rls::GLOBAL_TABLES), so the app role gets NO privilege on them: it reaches them only through the `sysops`
 * SECURITY DEFINER functions of sysops_entitlements.sql, which are bound to `app.tenant_id`. Staff write overrides as
 * `ledgerly_platform`; `ledgerly_sysops` (the functions' owner) gets exactly the privileges the functions use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_entitlement_overrides', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('key', 48);
            $table->jsonb('value');
            $table->text('reason');
            $table->uuid('set_by')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->primary(['tenant_id', 'key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('set_by')->references('id')->on('admin_users')->nullOnDelete();
        });

        Schema::create('usage_daily', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->date('day');
            $table->string('metric', 32);
            $table->bigInteger('value')->default(0);
            $table->primary(['tenant_id', 'day', 'metric']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE usage_daily ADD CONSTRAINT usage_daily_metric_check CHECK (metric IN ('journal_lines','api_calls','storage_bytes','reports'))");

        // Idempotency keys of usage bumps (an outbox message id, an attachment id): a replay adds nothing.
        Schema::create('usage_dedup', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('dedup_key', 120);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['tenant_id', 'dedup_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        $db = DB::connection();
        $tables = 'tenant_entitlement_overrides, usage_daily, usage_dedup';
        $db->statement("REVOKE ALL ON {$tables} FROM PUBLIC, ledgerly_app, ledgerly_report, ledgerly_platform");
        $db->statement('GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_entitlement_overrides TO ledgerly_platform');
        // What the functions read: the catalogue, the overrides and the tenant row; what they write: the counters.
        $db->statement('GRANT SELECT ON plans, plan_versions, tenant_entitlement_overrides, tenants TO ledgerly_sysops');
        $db->statement('GRANT SELECT, INSERT, UPDATE ON usage_daily TO ledgerly_sysops');
        $db->statement('GRANT INSERT ON usage_dedup TO ledgerly_sysops');

        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_entitlements.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.usage_summary()');
        DB::statement('DROP FUNCTION IF EXISTS sysops.bump_usage(text, bigint, text)');
        DB::statement('DROP FUNCTION IF EXISTS sysops.current_entitlements(text)');
        Schema::dropIfExists('usage_dedup');
        Schema::dropIfExists('usage_daily');
        Schema::dropIfExists('tenant_entitlement_overrides');
    }
};
