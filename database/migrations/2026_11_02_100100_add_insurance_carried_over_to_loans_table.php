<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The insurance premium a restructure moved onto this loan from the loan it
 * closed, still owed, interest-free and collected first. Kept apart from the
 * premium this loan's own release charged so the disclosure can show it on
 * its own line. Additive: no loan has carried any before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('insurance_carried_over', 12, 2)->default(0)->after('insurance_remaining_balance');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('insurance_carried_over');
        });
    }
};
