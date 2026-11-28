<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIC's own reference number for its answer to a submitted file (prompt
 * §14.8), recorded with the acknowledgement and then kept. Optional, as CIC
 * may not give one. New table, no rows yet: nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cic_submissions', function (Blueprint $table) {
            $table->string('cic_reference_no', 60)->nullable()->after('acknowledgement_notes');
        });
    }

    public function down(): void
    {
        Schema::table('cic_submissions', function (Blueprint $table) {
            $table->dropColumn('cic_reference_no');
        });
    }
};
