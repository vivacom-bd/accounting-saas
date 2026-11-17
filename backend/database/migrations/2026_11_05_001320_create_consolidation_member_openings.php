<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opening translated equity of a foreign subsidiary the group held before its first run in Ledgerly (13 §23 D16.4,
 * owner's decision 2026-11-17). Entered once by the accountant from the last consolidation done elsewhere: as of a date,
 * the equity per group equity account at historical rates and the translation reserve, in minor units of the group's
 * presentation currency, net (debit − credit). Editable until a run that uses it is approved; from then on the row is
 * locked and never changes or disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consolidation_member_openings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->uuid('member_id');
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            $table->date('as_of');
            $table->jsonb('equity')->default('{}');
            $table->bigInteger('translation_reserve')->default(0);
            $table->text('reason');
            $table->uuid('locked_by_run_id')->nullable();
            $table->foreign(['tenant_id', 'locked_by_run_id'])->references(['tenant_id', 'id'])->on('consolidation_runs')->restrictOnDelete();
            $table->timestampTz('locked_at')->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['member_id']);
        });
        DB::statement('ALTER TABLE consolidation_member_openings ADD CONSTRAINT consolidation_member_openings_locked_complete CHECK ((locked_by_run_id IS NULL) = (locked_at IS NULL))');
        Rls::enable(DB::connection(), 'consolidation_member_openings');

        DB::unprepared(<<<'SQL'
            -- Never deleted; once a run using it is approved, never changed (LG002).
            CREATE OR REPLACE FUNCTION consolidation_member_openings_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF purge_allowed(OLD.tenant_id) THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'consolidation_member_openings_guard: openings are never deleted' USING ERRCODE = 'LG002';
              END IF;
              IF OLD.locked_by_run_id IS NOT NULL THEN
                RAISE EXCEPTION 'consolidation_member_openings_guard: opening % is locked by run %', OLD.id, OLD.locked_by_run_id USING ERRCODE = 'LG002';
              END IF;
              RETURN NEW;
            END
            $$;

            CREATE TRIGGER consolidation_member_openings_guard BEFORE UPDATE OR DELETE ON consolidation_member_openings
              FOR EACH ROW EXECUTE FUNCTION consolidation_member_openings_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_member_openings');
        DB::unprepared('DROP FUNCTION IF EXISTS consolidation_member_openings_guard() CASCADE');
    }
};
