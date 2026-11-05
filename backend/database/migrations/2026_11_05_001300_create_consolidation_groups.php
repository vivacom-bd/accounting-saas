<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidation groups (13 §23 D16.1 and D16.2, plan 3c.29a). A group belongs to one tenant: a presentation currency,
 * a parent company and dated membership. Nothing here touches a company's books; the group has its own chart (group
 * accounts) and a mapping from every member account that carries a balance.
 *
 * Ownership is the parent's share in basis points (10000 = 100%), as a dated history: a row applies to results after
 * its `effective_on` date, and a later row replaces it. A group account with a `role` is one the engine posts to
 * (retained earnings, translation reserve, non-controlling interests, goodwill, ...): one per role per group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consolidation_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->string('name', 160);
            $table->char('presentation_currency', 3);
            $table->uuid('parent_company_id');
            $table->foreign(['tenant_id', 'parent_company_id'])->references(['tenant_id', 'id'])->on('companies')->restrictOnDelete();
            $table->bigInteger('ic_tolerance')->default(0);
            $table->string('status', 16)->default('active');
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });
        DB::statement("ALTER TABLE consolidation_groups ADD CONSTRAINT consolidation_groups_check CHECK (status IN ('active', 'archived') AND ic_tolerance >= 0)");
        Rls::enable(DB::connection(), 'consolidation_groups');

        Schema::create('consolidation_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->uuid('company_id');
            $table->foreign(['tenant_id', 'company_id'])->references(['tenant_id', 'id'])->on('companies')->restrictOnDelete();
            $table->string('role', 16);
            $table->date('acquisition_date')->nullable();
            $table->string('status', 16)->default('active');
            $table->date('disposed_on')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['group_id', 'company_id']);
        });
        foreach ([
            'consolidation_members_role_check' => "role IN ('parent', 'subsidiary') AND status IN ('active', 'disposed')",
            // A subsidiary has an acquisition date; the parent has none.
            'consolidation_members_date_check' => "(role = 'parent' AND acquisition_date IS NULL) OR (role = 'subsidiary' AND acquisition_date IS NOT NULL)",
            'consolidation_members_disposal_check' => "(status = 'disposed') = (disposed_on IS NOT NULL)",
        ] as $name => $expression) {
            DB::statement("ALTER TABLE consolidation_members ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
        DB::statement("CREATE UNIQUE INDEX consolidation_members_one_parent ON consolidation_members (group_id) WHERE role = 'parent'");
        Rls::enable(DB::connection(), 'consolidation_members');

        Schema::create('consolidation_ownerships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->uuid('member_id');
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            $table->date('effective_on');
            $table->integer('percent_bp');
            $table->string('note', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['member_id', 'effective_on']);
        });
        DB::statement('ALTER TABLE consolidation_ownerships ADD CONSTRAINT consolidation_ownerships_check CHECK (percent_bp BETWEEN 0 AND 10000)');
        Rls::enable(DB::connection(), 'consolidation_ownerships');

        Schema::create('consolidation_group_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('account_type', 16);
            $table->string('account_subtype', 64);
            $table->string('role', 32)->nullable();
            $table->jsonb('reporting_tags')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'group_id', 'id']);
            $table->unique(['group_id', 'code']);
        });
        DB::statement("ALTER TABLE consolidation_group_accounts ADD CONSTRAINT consolidation_group_accounts_type_check CHECK (account_type IN ('asset', 'liability', 'equity', 'income', 'expense'))");
        DB::statement('CREATE UNIQUE INDEX consolidation_group_accounts_one_role ON consolidation_group_accounts (group_id, role) WHERE role IS NOT NULL');
        Rls::enable(DB::connection(), 'consolidation_group_accounts');

        Schema::create('consolidation_account_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->uuid('group_id');
            $table->foreign(['tenant_id', 'group_id'])->references(['tenant_id', 'id'])->on('consolidation_groups')->restrictOnDelete();
            $table->uuid('member_id');
            $table->foreign(['tenant_id', 'member_id'])->references(['tenant_id', 'id'])->on('consolidation_members')->restrictOnDelete();
            Rls::companyRef($table, 'account_id', 'accounts');
            $table->uuid('group_account_id');
            $table->foreign(['tenant_id', 'group_id', 'group_account_id'])->references(['tenant_id', 'group_id', 'id'])->on('consolidation_group_accounts')->restrictOnDelete();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['member_id', 'account_id']);
            $table->index(['group_id', 'group_account_id']);
        });
        Rls::enable(DB::connection(), 'consolidation_account_maps');
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_account_maps');
        Schema::dropIfExists('consolidation_group_accounts');
        Schema::dropIfExists('consolidation_ownerships');
        Schema::dropIfExists('consolidation_members');
        Schema::dropIfExists('consolidation_groups');
    }
};
