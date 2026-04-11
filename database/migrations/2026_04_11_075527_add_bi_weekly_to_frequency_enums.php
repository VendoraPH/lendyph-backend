<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE loans MODIFY frequency ENUM('daily','weekly','bi_weekly','semi_monthly','monthly') NOT NULL");
        DB::statement("ALTER TABLE loan_products MODIFY frequency ENUM('daily','weekly','bi_weekly','semi_monthly','monthly') NOT NULL");
    }

    public function down(): void
    {
        DB::table('loans')->where('frequency', 'bi_weekly')->update(['frequency' => 'semi_monthly']);
        DB::table('loan_products')->where('frequency', 'bi_weekly')->update(['frequency' => 'semi_monthly']);
        DB::statement("ALTER TABLE loans MODIFY frequency ENUM('daily','weekly','semi_monthly','monthly') NOT NULL");
        DB::statement("ALTER TABLE loan_products MODIFY frequency ENUM('daily','weekly','semi_monthly','monthly') NOT NULL");
    }
};
