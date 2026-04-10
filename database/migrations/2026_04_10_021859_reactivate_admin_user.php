<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reactivate the seed admin user.
     *
     * The previous align_rbac_with_frontend migration converted 'deactivated' rows
     * to 'inactive', which inadvertently locked out the admin user on production
     * (it was previously deactivated for unknown reasons before the migration ran).
     */
    public function up(): void
    {
        DB::table('users')
            ->where('username', 'admin')
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        // No-op — we don't want to re-deactivate admin on rollback
    }
};
