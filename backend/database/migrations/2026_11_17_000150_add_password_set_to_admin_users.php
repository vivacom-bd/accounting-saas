<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set-password invitations for staff (plan Task 8): only the SHA-256 of the emailed token is stored, with its expiry.
 * The columns sit on admin_users, so the platform role's existing table grants already cover them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->char('password_set_token_hash', 64)->nullable()->unique();
            $table->timestampTz('password_set_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['password_set_token_hash', 'password_set_expires_at']);
        });
    }
};
