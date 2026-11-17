<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Failures of the nightly audit chain verification (13 §14, plan 4.2): one row per distinct break. `fingerprint` hashes the
 * problem code, the first bad event and the detail, so a chain that stays broken in the same way is recorded once, not every night.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            Rls::tenantColumn($table);
            $table->bigInteger('broken_at_id')->nullable();
            $table->string('problem', 32);
            $table->text('detail');
            $table->boolean('full_check')->default(false);
            $table->char('fingerprint', 64);
            $table->timestampTz('detected_at');
            $table->unique(['tenant_id', 'fingerprint']);
        });
        Rls::enable(DB::connection(), 'audit_chain_findings');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_findings');
    }
};
