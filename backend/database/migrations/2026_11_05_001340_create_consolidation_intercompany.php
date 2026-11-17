<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Intercompany matching and eliminations (13 §23 D16.7, plan 3c.29c). Nothing here touches a company's books: the flags
 * live on the group's mapping, beside the member account they describe.
 *
 *  - `consolidation_account_maps.ic_class`: the member account holds intragroup items of that class (null = not
 *    intercompany). The counterparty is `counterparty_member_id` when the whole account is with one member, otherwise it
 *    comes from the contact on each line through `consolidation_ic_contacts` (lines whose contact is not a member's are
 *    external). `net_investment` designates a loan as part of the net investment in a foreign operation (IAS 21.15, .32).
 *  - `consolidation_ic_contacts`: a member company's contact that stands for another member of the group.
 *  - `consolidation_ic_matches`: the run's matching report, one row per pair of members and class, in the presentation
 *    currency after translation. It freezes with the run; the only change is a second person accepting a difference above
 *    the group's tolerance, once, with a reason (never the person who computed the run).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consolidation_account_maps', function (Blueprint $table) {
            $table->string('ic_class', 16)->nullable();
            $table->uuid('counterparty_member_id')->nullable();
            $table->foreign(['tenant_id', 'counterparty_member_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            $table->boolean('net_investment')->default(false);
        });
        foreach ([
            'consolidation_account_maps_ic_class_check' => "ic_class IS NULL OR ic_class IN ('balance', 'loan', 'income_expense', 'dividend')",
            'consolidation_account_maps_ic_counterparty_check' => 'ic_class IS NOT NULL OR counterparty_member_id IS NULL',
            'consolidation_account_maps_ic_self_check' => 'counterparty_member_id IS DISTINCT FROM member_id',
            'consolidation_account_maps_net_investment_check' => "NOT net_investment OR ic_class = 'loan'",
        ] as $name => $expression) {
            DB::statement("ALTER TABLE consolidation_account_maps ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        Schema::create('consolidation_ic_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->uuid('member_id');
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            Rls::companyRef($table, 'contact_id', 'contacts');
            $table->uuid('counterparty_member_id');
            $table->foreign(['tenant_id', 'counterparty_member_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['member_id', 'contact_id']);
        });
        DB::statement('ALTER TABLE consolidation_ic_contacts ADD CONSTRAINT consolidation_ic_contacts_self_check CHECK (counterparty_member_id <> member_id)');
        Rls::enable(DB::connection(), 'consolidation_ic_contacts');

        Schema::create('consolidation_ic_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('run_id');
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('consolidation_runs')->restrictOnDelete();
            $table->uuid('member_a_id');
            $table->foreign(['tenant_id', 'member_a_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            $table->uuid('member_b_id');
            $table->foreign(['tenant_id', 'member_b_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            $table->string('ic_class', 16);
            $table->bigInteger('side_a');
            $table->bigInteger('side_b');
            $table->bigInteger('difference');
            $table->bigInteger('matched');
            $table->string('cause', 24)->nullable();
            $table->boolean('exceeds_tolerance');
            $table->jsonb('detail')->default('{}');
            $table->uuid('accepted_by')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['run_id', 'member_a_id', 'member_b_id', 'ic_class']);
        });
        foreach ([
            'consolidation_ic_matches_class_check' => "ic_class IN ('balance', 'loan', 'income_expense', 'dividend')",
            'consolidation_ic_matches_cause_check' => "cause IS NULL OR cause IN ('fx', 'timing', 'missing_counterpart')",
            'consolidation_ic_matches_pair_check' => 'member_a_id < member_b_id',
            'consolidation_ic_matches_figures_check' => 'difference = side_a + side_b AND matched >= 0',
            'consolidation_ic_matches_accepted_check' => '(accepted_by IS NULL) = (accepted_at IS NULL) AND (accepted_by IS NULL OR (exceeds_tolerance AND length(trim(reason)) > 0))',
        ] as $name => $expression) {
            DB::statement("ALTER TABLE consolidation_ic_matches ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
        Rls::enable(DB::connection(), 'consolidation_ic_matches');

        DB::unprepared(<<<'SQL'
            -- The matching report freezes with its run. A difference above tolerance may be accepted once, with a reason, by
            -- someone other than the person who computed the run, while the run is still computed (LG002).
            CREATE OR REPLACE FUNCTION consolidation_ic_matches_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              r record;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF purge_allowed(OLD.tenant_id) THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'consolidation_ic_matches_guard: matches are never deleted' USING ERRCODE = 'LG002';
              END IF;
              IF OLD.accepted_by IS NOT NULL THEN
                RAISE EXCEPTION 'consolidation_ic_matches_guard: match % is already accepted', OLD.id USING ERRCODE = 'LG002';
              END IF;
              IF (to_jsonb(NEW) - 'accepted_by' - 'accepted_at' - 'reason' - 'updated_at')
                 IS DISTINCT FROM (to_jsonb(OLD) - 'accepted_by' - 'accepted_at' - 'reason' - 'updated_at') THEN
                RAISE EXCEPTION 'consolidation_ic_matches_guard: only the acceptance of match % may change', OLD.id USING ERRCODE = 'LG002';
              END IF;
              SELECT status, created_by INTO r FROM consolidation_runs WHERE id = OLD.run_id;
              IF r.status IS DISTINCT FROM 'computed' THEN
                RAISE EXCEPTION 'consolidation_ic_matches_guard: run % is %', OLD.run_id, r.status USING ERRCODE = 'LG002';
              END IF;
              IF NEW.accepted_by IS NOT DISTINCT FROM r.created_by THEN
                RAISE EXCEPTION 'consolidation_ic_matches_guard: a second person accepts a difference' USING ERRCODE = 'LG002';
              END IF;
              RETURN NEW;
            END
            $$;

            CREATE TRIGGER consolidation_ic_matches_guard BEFORE UPDATE OR DELETE ON consolidation_ic_matches
              FOR EACH ROW EXECUTE FUNCTION consolidation_ic_matches_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_ic_matches');
        DB::unprepared('DROP FUNCTION IF EXISTS consolidation_ic_matches_guard() CASCADE');
        Schema::dropIfExists('consolidation_ic_contacts');
        foreach (['ic_class_check', 'ic_counterparty_check', 'ic_self_check', 'net_investment_check'] as $c) {
            DB::statement("ALTER TABLE consolidation_account_maps DROP CONSTRAINT IF EXISTS consolidation_account_maps_{$c}");
        }
        Schema::table('consolidation_account_maps', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'counterparty_member_id']);
            $table->dropColumn(['ic_class', 'counterparty_member_id', 'net_investment']);
        });
    }
};
