<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE borrowers MODIFY status ENUM('active', 'inactive', 'blacklisted') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        // Revert any blacklisted rows to inactive first, then shrink the enum
        DB::table('borrowers')->where('status', 'blacklisted')->update(['status' => 'inactive']);
        DB::statement("ALTER TABLE borrowers MODIFY status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
    }
};
