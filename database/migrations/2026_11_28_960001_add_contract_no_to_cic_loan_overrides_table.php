<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A loan override's own CIC contract number (review M-H1): for a loan whose
 * display number is longer than CIC's 38 characters or collides with another
 * loan's. Letters, digits and dashes, unique among overrides (and, checked by
 * the override service, among `loans.cic_contract_no`). The capture's record
 * builder reads it before the display number. New column, nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cic_loan_overrides', function (Blueprint $table) {
            $table->string('contract_no', 38)->nullable()->unique()->after('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::table('cic_loan_overrides', function (Blueprint $table) {
            $table->dropUnique(['contract_no']);
            $table->dropColumn('contract_no');
        });
    }
};
