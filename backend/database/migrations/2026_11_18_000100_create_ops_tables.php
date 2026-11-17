<?php

declare(strict_types=1);

use App\Support\Database\SqlFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform operations (plan Task 17): `platform_settings` (maintenance mode, later Task 16's switches),
 * `tenant_rate_limits` and Pennant's `features` table, all platform tables (Rls::GLOBAL_TABLES). ledgerly_platform
 * writes them; the app role reads maintenance and its tenant's rate limit only through sysops functions, and may only
 * SELECT features (it resolves flags, never stores them: see LedgerlyFeatureDriver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at', 6);
            $table->foreign('updated_by')->references('id')->on('admin_users')->nullOnDelete();
        });

        Schema::create('tenant_rate_limits', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->integer('per_minute');
            $table->text('reason');
            $table->uuid('set_by');
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('set_by')->references('id')->on('admin_users')->restrictOnDelete();
        });

        // Laravel Pennant's own schema (vendor/laravel/pennant/database/migrations), published here so the grants follow.
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();
            $table->unique(['name', 'scope']);
        });

        $db = DB::connection();
        $db->statement('ALTER TABLE tenant_rate_limits ADD CONSTRAINT tenant_rate_limits_per_minute_positive CHECK (per_minute > 0)');
        $db->statement('REVOKE ALL ON platform_settings, tenant_rate_limits, features FROM PUBLIC, ledgerly_app, ledgerly_report');
        $db->statement('GRANT SELECT, INSERT, UPDATE ON platform_settings TO ledgerly_platform');
        $db->statement('GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_rate_limits TO ledgerly_platform');
        $db->statement('GRANT SELECT, INSERT, UPDATE, DELETE ON features TO ledgerly_platform');
        $db->statement('GRANT USAGE, SELECT ON features_id_seq TO ledgerly_platform');
        $db->statement('GRANT SELECT ON features TO ledgerly_app');
        $db->statement('GRANT SELECT ON platform_settings, tenant_rate_limits TO ledgerly_sysops');

        $db->statement('SET LOCAL ROLE ledgerly_sysops');
        SqlFile::run($db, 'sysops_ops.sql');
        $db->statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sysops.admin_outbox_backlog()');
        DB::statement('DROP FUNCTION IF EXISTS sysops.tenant_rate_limit()');
        DB::statement('DROP FUNCTION IF EXISTS sysops.platform_maintenance()');
        Schema::dropIfExists('features');
        Schema::dropIfExists('tenant_rate_limits');
        Schema::dropIfExists('platform_settings');
    }
};
