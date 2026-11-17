<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A locked opening translated equity unlocks when the run that locked it is reversed (owner's decision 2026-11-17).
 * The guard now allows exactly two transitions besides edits of an unlocked row:
 *  - lock: locked_by_run_id set from NULL, only to an approved run of the same group;
 *  - unlock: locked_by_run_id and locked_at cleared, only when the run they name is reversed, with nothing else changed
 *    (lock_version, updated_by and updated_at aside).
 * Everything else on a locked row, and every delete, is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION consolidation_member_openings_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              run_status text;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF purge_allowed(OLD.tenant_id) THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'consolidation_member_openings_guard: openings are never deleted' USING ERRCODE = 'LG002';
              END IF;
              IF OLD.locked_by_run_id IS NOT NULL THEN
                SELECT status INTO run_status FROM consolidation_runs WHERE id = OLD.locked_by_run_id;
                IF NEW.locked_by_run_id IS NULL AND NEW.locked_at IS NULL AND run_status = 'reversed'
                   AND (to_jsonb(NEW) - 'locked_by_run_id' - 'locked_at' - 'lock_version' - 'updated_by' - 'updated_at')
                       = (to_jsonb(OLD) - 'locked_by_run_id' - 'locked_at' - 'lock_version' - 'updated_by' - 'updated_at') THEN
                  RETURN NEW;
                END IF;
                RAISE EXCEPTION 'consolidation_member_openings_guard: opening % is locked by run % (%)', OLD.id, OLD.locked_by_run_id, run_status USING ERRCODE = 'LG002';
              END IF;
              IF NEW.locked_by_run_id IS NOT NULL THEN
                SELECT status INTO run_status FROM consolidation_runs WHERE id = NEW.locked_by_run_id AND group_id = NEW.group_id;
                IF run_status IS DISTINCT FROM 'approved' THEN
                  RAISE EXCEPTION 'consolidation_member_openings_guard: opening % can only be locked by an approved run of its group', OLD.id USING ERRCODE = 'LG002';
                END IF;
              END IF;
              RETURN NEW;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
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
        SQL);
    }
};
