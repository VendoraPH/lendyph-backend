<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `loans.cic_record_type`: the CSDF record type (CI or CN) a loan's
 * structure gave it when it was first captured, set once with
 * `cic_contract_no` and never changed, so an extension that adds a period
 * can't turn the same contract from CN into CI. A loan override's record
 * type still wins. No loan has been captured yet: nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->enum('cic_record_type', ['CI', 'CN'])->nullable()->after('cic_contract_no');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('cic_record_type');
        });
    }
};
