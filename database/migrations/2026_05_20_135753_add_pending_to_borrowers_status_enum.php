<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE borrowers MODIFY status ENUM('active', 'inactive', 'blacklisted', 'pending') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::table('borrowers')->where('status', 'pending')->update(['status' => 'inactive']);
        DB::statement("ALTER TABLE borrowers MODIFY status ENUM('active', 'inactive', 'blacklisted') NOT NULL DEFAULT 'active'");
    }
};
