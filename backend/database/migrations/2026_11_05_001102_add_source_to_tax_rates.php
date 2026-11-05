<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a rate came from and as of when it was checked (plan 3c.22: every rate is data with a source and an as-of date). Both are
 * free text and a date, set by a country pack or by whoever adds the rate; they change nothing about how tax is computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->string('source', 255)->nullable();
            $table->date('source_as_of')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_as_of']);
        });
    }
};
