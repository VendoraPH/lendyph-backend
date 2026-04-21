<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE loans MODIFY frequency ENUM('daily','weekly','bi_weekly','semi_monthly','monthly','upon_maturity') NOT NULL");
        DB::statement("ALTER TABLE loan_products MODIFY frequency ENUM('daily','weekly','bi_weekly','semi_monthly','monthly','upon_maturity') NOT NULL");
    }

    public function down(): void
    {
        DB::table('loans')->where('frequency', 'upon_maturity')->update(['frequency' => 'monthly']);
        DB::table('loan_products')->where('frequency', 'upon_maturity')->update(['frequency' => 'monthly']);
        DB::statement("ALTER TABLE loans MODIFY frequency ENUM('daily','weekly','bi_weekly','semi_monthly','monthly') NOT NULL");
        DB::statement("ALTER TABLE loan_products MODIFY frequency ENUM('daily','weekly','bi_weekly','semi_monthly','monthly') NOT NULL");
    }
};
