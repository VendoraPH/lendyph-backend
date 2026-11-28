<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A loan's CIC Provider Contract No, frozen the first time a capture reports
 * the loan (from Loan::displayNumber()), so CIC always reads the same contract
 * under the same number. Set once, never changed and never mass-assigned. A
 * display number over 38 characters is a capture issue, never cut short. No
 * backfill: existing loans get theirs at their first capture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('cic_contract_no', 38)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique(['cic_contract_no']);
            $table->dropColumn('cic_contract_no');
        });
    }
};
