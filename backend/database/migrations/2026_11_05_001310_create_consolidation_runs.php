<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidation runs, the worksheet and the consolidation ledger (13 §23 D16.3, plan 3c.29b).
 *
 * The consolidation ledger is separate from every company's books: its entries have their own tables, numbers (gapless per
 * group and calendar year of the entry date) and balance check, and never touch journals, balances, sequences or periods
 * of a company. Entries are append-only: a draft becomes posted exactly once (with its number and a second person's
 * approval), a posted entry changes only by being marked reversed when its reversing entry posts, and nothing is deleted.
 * Lines are never updated or deleted. The worksheet lines of a run freeze at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consolidation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->char('currency', 3);
            $table->string('status', 16)->default('computed');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('summary')->default('{}');
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['group_id', 'period_to']);
        });
        foreach ([
            'consolidation_runs_status_check' => "status IN ('computed', 'approved', 'reversed', 'void')",
            'consolidation_runs_period_check' => 'period_to >= period_from',
            'consolidation_runs_maker_checker' => 'approved_by IS NULL OR approved_by IS DISTINCT FROM created_by',
            'consolidation_runs_approved_complete' => "status NOT IN ('approved', 'reversed') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)",
        ] as $name => $expression) {
            DB::statement("ALTER TABLE consolidation_runs ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
        // One live run per group and end date; a void or reversed run frees the date.
        DB::statement("CREATE UNIQUE INDEX consolidation_runs_one_live ON consolidation_runs (group_id, period_to) WHERE status IN ('computed', 'approved')");
        Rls::enable(DB::connection(), 'consolidation_runs');

        Schema::create('consolidation_run_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('run_id');
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('consolidation_runs')->restrictOnDelete();
            $table->uuid('member_id');
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            $table->uuid('company_id');
            $table->char('currency', 3);
            $table->date('window_from');
            $table->bigInteger('ledger_version');
            $table->string('closing_rate', 40)->nullable();
            $table->string('average_rate', 40)->nullable();
            $table->jsonb('rate_detail')->default('{}');
            $table->jsonb('state')->default('{}');
            $table->timestampsTz();
            $table->unique(['run_id', 'member_id']);
        });
        Rls::enable(DB::connection(), 'consolidation_run_members');

        Schema::create('consolidation_run_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('run_id');
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('consolidation_runs')->restrictOnDelete();
            $table->uuid('member_id')->nullable();
            $table->uuid('group_account_id');
            $table->string('kind', 16);
            $table->string('source', 24)->nullable();
            $table->uuid('entry_id')->nullable();
            $table->bigInteger('amount');
            $table->timestampsTz();
            $table->index(['run_id', 'kind']);
        });
        DB::statement("ALTER TABLE consolidation_run_lines ADD CONSTRAINT consolidation_run_lines_kind_check CHECK (kind IN ('local', 'translated', 'adjustment', 'consolidated'))");
        Rls::enable(DB::connection(), 'consolidation_run_lines');

        Schema::create('consolidation_rate_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->char('currency', 3);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('rate', 40);
            $table->string('reason', 255);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['group_id', 'currency', 'period_from', 'period_to']);
        });
        Rls::enable(DB::connection(), 'consolidation_rate_overrides');

        Schema::create('consolidation_sequences', function (Blueprint $table) {
            $table->uuid('group_id');
            Rls::tenantColumn($table);
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->integer('year');
            $table->bigInteger('next_value')->default(1);
            $table->timestampsTz();
            $table->primary(['group_id', 'year']);
        });
        Rls::enable(DB::connection(), 'consolidation_sequences');

        Schema::create('consolidation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->uuid('run_id')->nullable();
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('consolidation_runs')->restrictOnDelete();
            $table->string('number', 32)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('kind', 32);
            $table->date('entry_date');
            $table->uuid('member_id')->nullable();
            $table->text('memo')->nullable();
            $table->jsonb('detail')->default('{}');
            $table->bigInteger('total_debit')->default(0);
            $table->bigInteger('total_credit')->default(0);
            $table->uuid('reverses_entry_id')->nullable();
            $table->uuid('reversed_by_id')->nullable();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['group_id', 'number']);
            $table->index(['group_id', 'status', 'entry_date']);
        });
        foreach ([
            'consolidation_entries_status_check' => "status IN ('draft', 'posted', 'reversed', 'void')",
            'consolidation_entries_balanced' => 'total_debit = total_credit AND total_debit >= 0',
            // A reversal mirrors an entry a second person already approved; reversing needs the approve right and a reason instead.
            'consolidation_entries_maker_checker' => 'approved_by IS NULL OR approved_by IS DISTINCT FROM created_by OR reverses_entry_id IS NOT NULL',
            'consolidation_entries_posted_complete' => "status NOT IN ('posted', 'reversed') OR (number IS NOT NULL AND posted_at IS NOT NULL AND approved_by IS NOT NULL)",
        ] as $name => $expression) {
            DB::statement("ALTER TABLE consolidation_entries ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
        Rls::enable(DB::connection(), 'consolidation_entries');

        Schema::create('consolidation_entry_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('entry_id');
            $table->foreign(['tenant_id', 'entry_id'])->references(['tenant_id', 'id'])->on('consolidation_entries')->restrictOnDelete();
            $table->smallInteger('line_no');
            $table->uuid('group_account_id');
            $table->uuid('member_id')->nullable();
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->string('description', 255)->nullable();
            $table->timestampsTz();
            $table->unique(['entry_id', 'line_no']);
            $table->index(['group_account_id']);
        });
        DB::statement('ALTER TABLE consolidation_entry_lines ADD CONSTRAINT consolidation_entry_lines_sides CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0))');
        Rls::enable(DB::connection(), 'consolidation_entry_lines');

        DB::unprepared(<<<'SQL'
            -- Entries: nothing is deleted; a posted entry only becomes reversed; a void or reversed one never changes (LG002).
            CREATE OR REPLACE FUNCTION consolidation_entries_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF purge_allowed(OLD.tenant_id) THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'consolidation_entries_guard: entries are never deleted' USING ERRCODE = 'LG002';
              END IF;
              IF OLD.status IN ('void', 'reversed') THEN
                RAISE EXCEPTION 'consolidation_entries_guard: entry % is %', OLD.id, OLD.status USING ERRCODE = 'LG002';
              END IF;
              IF OLD.status = 'posted' THEN
                IF NEW.status IS DISTINCT FROM OLD.status AND NEW.status <> 'reversed' THEN
                  RAISE EXCEPTION 'consolidation_entries_guard: entry % cannot move from posted to %', OLD.id, NEW.status USING ERRCODE = 'LG002';
                END IF;
                IF (to_jsonb(NEW) - 'status' - 'reversed_by_id' - 'lock_version' - 'updated_at')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reversed_by_id' - 'lock_version' - 'updated_at') THEN
                  RAISE EXCEPTION 'consolidation_entries_guard: entry % is posted and only whitelisted columns may change', OLD.id USING ERRCODE = 'LG002';
                END IF;
              END IF;
              RETURN NEW;
            END
            $$;

            CREATE TRIGGER consolidation_entries_guard BEFORE UPDATE OR DELETE ON consolidation_entries
              FOR EACH ROW EXECUTE FUNCTION consolidation_entries_guard();

            -- Lines: inserted only into a draft entry, never changed or deleted (LG001).
            CREATE OR REPLACE FUNCTION consolidation_entry_lines_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              parent_status text;
            BEGIN
              IF TG_OP IN ('UPDATE', 'DELETE') THEN
                IF TG_OP = 'DELETE' AND purge_allowed(OLD.tenant_id) THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'consolidation_entry_lines_guard: lines are never changed or deleted' USING ERRCODE = 'LG001';
              END IF;
              SELECT status INTO parent_status FROM consolidation_entries WHERE id = NEW.entry_id;
              IF parent_status IS DISTINCT FROM 'draft' THEN
                RAISE EXCEPTION 'consolidation_entry_lines_guard: entry % is %', NEW.entry_id, parent_status USING ERRCODE = 'LG001';
              END IF;
              RETURN NEW;
            END
            $$;

            CREATE TRIGGER consolidation_entry_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON consolidation_entry_lines
              FOR EACH ROW EXECUTE FUNCTION consolidation_entry_lines_guard();

            -- An entry that becomes posted must balance line by line and carry matching totals (LG003). Deferred to commit.
            CREATE OR REPLACE FUNCTION consolidation_entry_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              n int;
              sum_debit bigint;
              sum_credit bigint;
            BEGIN
              SELECT count(*), coalesce(sum(debit), 0), coalesce(sum(credit), 0) INTO n, sum_debit, sum_credit
                FROM consolidation_entry_lines WHERE entry_id = NEW.id;
              IF n < 2 OR sum_debit <> sum_credit OR sum_debit <> NEW.total_debit OR sum_credit <> NEW.total_credit THEN
                RAISE EXCEPTION 'consolidation_entry_balanced: entry % has % lines, debit %, credit %, totals %/%',
                  NEW.id, n, sum_debit, sum_credit, NEW.total_debit, NEW.total_credit USING ERRCODE = 'LG003';
              END IF;
              RETURN NULL;
            END
            $$;

            CREATE CONSTRAINT TRIGGER consolidation_entry_balanced_on_post
              AFTER UPDATE OF status ON consolidation_entries
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW
              WHEN (NEW.status = 'posted' AND OLD.status IS DISTINCT FROM 'posted')
              EXECUTE FUNCTION consolidation_entry_balanced();

            -- Runs: approved only moves to reversed; void and reversed are final; no deletes.
            CREATE OR REPLACE FUNCTION consolidation_runs_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF purge_allowed(OLD.tenant_id) THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'consolidation_runs_guard: runs are never deleted' USING ERRCODE = 'LG002';
              END IF;
              IF OLD.status IN ('void', 'reversed') THEN
                RAISE EXCEPTION 'consolidation_runs_guard: run % is %', OLD.id, OLD.status USING ERRCODE = 'LG002';
              END IF;
              IF OLD.status = 'approved' THEN
                IF NEW.status IS DISTINCT FROM OLD.status AND NEW.status <> 'reversed' THEN
                  RAISE EXCEPTION 'consolidation_runs_guard: run % cannot move from approved to %', OLD.id, NEW.status USING ERRCODE = 'LG002';
                END IF;
                IF (to_jsonb(NEW) - 'status' - 'reversed_by' - 'reversed_at' - 'reason' - 'lock_version' - 'updated_at')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reversed_by' - 'reversed_at' - 'reason' - 'lock_version' - 'updated_at') THEN
                  RAISE EXCEPTION 'consolidation_runs_guard: run % is approved and only whitelisted columns may change', OLD.id USING ERRCODE = 'LG002';
                END IF;
              END IF;
              RETURN NEW;
            END
            $$;

            CREATE TRIGGER consolidation_runs_guard BEFORE UPDATE OR DELETE ON consolidation_runs
              FOR EACH ROW EXECUTE FUNCTION consolidation_runs_guard();

            -- The worksheet and each member's rates and closing position freeze with the run.
            CREATE OR REPLACE FUNCTION consolidation_frozen_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' AND purge_allowed(OLD.tenant_id) THEN RETURN OLD; END IF;
              RAISE EXCEPTION 'consolidation_frozen_guard: % rows are never changed or deleted', TG_TABLE_NAME USING ERRCODE = 'LG002';
            END
            $$;

            CREATE TRIGGER consolidation_run_lines_guard BEFORE UPDATE OR DELETE ON consolidation_run_lines
              FOR EACH ROW EXECUTE FUNCTION consolidation_frozen_guard();
            CREATE TRIGGER consolidation_run_members_guard BEFORE UPDATE OR DELETE ON consolidation_run_members
              FOR EACH ROW EXECUTE FUNCTION consolidation_frozen_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_entry_lines');
        Schema::dropIfExists('consolidation_entries');
        Schema::dropIfExists('consolidation_sequences');
        Schema::dropIfExists('consolidation_rate_overrides');
        Schema::dropIfExists('consolidation_run_lines');
        Schema::dropIfExists('consolidation_run_members');
        Schema::dropIfExists('consolidation_runs');
        DB::unprepared('DROP FUNCTION IF EXISTS consolidation_entries_guard() CASCADE; DROP FUNCTION IF EXISTS consolidation_entry_lines_guard() CASCADE; DROP FUNCTION IF EXISTS consolidation_entry_balanced() CASCADE; DROP FUNCTION IF EXISTS consolidation_runs_guard() CASCADE; DROP FUNCTION IF EXISTS consolidation_frozen_guard() CASCADE');
    }
};
