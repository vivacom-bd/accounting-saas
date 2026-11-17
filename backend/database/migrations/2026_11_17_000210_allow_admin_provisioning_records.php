<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provisioning from admin (plan Task 6): a tenant's first lifecycle event has no previous state, and the owner invitation
 * is issued by a staff member, who is not a `users` row, so `invitations.invited_by` may be empty (the audit trail names
 * the admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tenant_lifecycle_events ALTER COLUMN from_status DROP NOT NULL');
        DB::statement('ALTER TABLE invitations ALTER COLUMN invited_by DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE tenant_lifecycle_events SET from_status = to_status WHERE from_status IS NULL');
        DB::statement('ALTER TABLE tenant_lifecycle_events ALTER COLUMN from_status SET NOT NULL');
        DB::statement('DELETE FROM invitations WHERE invited_by IS NULL');
        DB::statement('ALTER TABLE invitations ALTER COLUMN invited_by SET NOT NULL');
    }
};
