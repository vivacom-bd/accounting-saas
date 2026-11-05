<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saved report layouts and scheduled delivery (plan 3c.23, finishes 2.15).
 *
 * - `report_layouts`: a name for a report's chosen parameters. `user_id` is the person who owns it (a layout always has an owner:
 *   a schedule runs as them); `visibility` is `private` or `company`. `params` holds the whitelisted parameters, with a period rule
 *   (`this_month`, `last_month`, ...) instead of a fixed period where the person chose one. `is_default` is the owner's own default
 *   for that report, at most one per (owner, report). A layout never holds figures, only parameters.
 * - `report_schedules`: at most one per layout. Friendly fields instead of cron: frequency, weekday, day of month or the last day,
 *   the hour in the company's timezone. Recipients are company members (`recipient_user_ids`) and/or outside addresses
 *   (`recipient_emails`). `active` is false when the owner paused it or the system did (`paused_reason`).
 * - `report_schedule_runs`: one row per (schedule, due date), unique in the database. Inserting it is the claim that a due date was
 *   taken, so two overlapping scheduler runs, or a run repeated after a crash, queue the report once. It is also the run history:
 *   how it ended and, when it failed, in plain words why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_layouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('report_code', 40);
            $table->string('name', 80);
            $table->string('visibility', 8)->default('private');
            $table->jsonb('params')->default('{}');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'company_id', 'id']);
            $table->unique(['company_id', 'user_id', 'report_code', 'name']);
        });
        DB::statement("ALTER TABLE report_layouts ADD CONSTRAINT report_layouts_visibility CHECK (visibility IN ('private', 'company'))");
        DB::statement('CREATE UNIQUE INDEX report_layouts_one_default ON report_layouts (company_id, user_id, report_code) WHERE is_default');
        Rls::enable(DB::connection(), 'report_layouts');

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->uuid('layout_id');
            $table->foreign(['tenant_id', 'company_id', 'layout_id'])->references(['tenant_id', 'company_id', 'id'])->on('report_layouts')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('frequency', 10);
            $table->unsignedSmallInteger('weekday')->nullable();
            $table->unsignedSmallInteger('month_day')->nullable();
            $table->boolean('last_day')->default(false);
            $table->unsignedSmallInteger('hour')->default(7);
            $table->string('format', 4);
            $table->jsonb('recipient_user_ids')->default('[]');
            $table->jsonb('recipient_emails')->default('[]');
            $table->boolean('active')->default(true);
            $table->string('paused_reason', 40)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampTz('last_run_at')->nullable();
            $table->string('last_status', 10)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'company_id', 'id']);
            $table->unique('layout_id');
        });
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_frequency CHECK (frequency IN ('daily', 'weekly', 'monthly', 'quarterly'))");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_format CHECK (format IN ('pdf', 'xlsx', 'csv'))");
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_hour CHECK (hour BETWEEN 0 AND 23)');
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_weekday CHECK (weekday IS NULL OR weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_month_day CHECK (month_day IS NULL OR month_day BETWEEN 1 AND 28)');
        Rls::enable(DB::connection(), 'report_schedules');

        Schema::create('report_schedule_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            Rls::companyColumn($table);
            $table->uuid('schedule_id');
            $table->foreign(['tenant_id', 'company_id', 'schedule_id'])->references(['tenant_id', 'company_id', 'id'])->on('report_schedules')->cascadeOnDelete();
            /** The company's own date this run was due on. */
            $table->date('due_on');
            $table->string('status', 10)->default('queued');
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->unsignedInteger('bytes')->nullable();
            $table->timestampTz('queued_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unique(['schedule_id', 'due_on']);
            $table->index(['schedule_id', 'due_on']);
        });
        DB::statement("ALTER TABLE report_schedule_runs ADD CONSTRAINT report_schedule_runs_status CHECK (status IN ('queued', 'running', 'sent', 'failed', 'skipped'))");
        Rls::enable(DB::connection(), 'report_schedule_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_runs');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_layouts');
    }
};
