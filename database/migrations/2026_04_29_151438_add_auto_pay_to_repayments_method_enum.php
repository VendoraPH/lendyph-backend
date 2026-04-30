<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE repayments MODIFY method ENUM('cash', 'gcash', 'maya', 'bank_transfer', 'online', 'auto_pay') NOT NULL DEFAULT 'cash'"
        );
    }

    public function down(): void
    {
        DB::table('repayments')->where('method', 'auto_pay')->update(['method' => 'bank_transfer']);
        DB::statement(
            "ALTER TABLE repayments MODIFY method ENUM('cash', 'gcash', 'maya', 'bank_transfer', 'online') NOT NULL DEFAULT 'cash'"
        );
    }
};
